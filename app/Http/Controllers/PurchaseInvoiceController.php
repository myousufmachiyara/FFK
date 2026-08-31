<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseInvoiceAttachment;
use App\Models\PurchaseStatusHistory;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Voucher;
use App\Models\MeasurementUnit;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PurchaseInvoiceController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Account resolution helpers — never hardcode IDs.
    // Codes come from config/purchase_accounts.php.
    // ─────────────────────────────────────────────────────────────
    private function resolveAccount(string $configKey, string $label): ChartOfAccounts
    {
        $code = config("purchase_accounts.{$configKey}");
        $account = ChartOfAccounts::where('account_code', $code)->first();

        if (!$account) {
            throw new \Exception("{$label} account (code {$code}) not found. Check config/purchase_accounts.php and your Chart of Accounts.");
        }

        return $account;
    }

    private function inventoryAccount(): ChartOfAccounts
    {
        return $this->resolveAccount('inventory', 'Inventory / Stock in Hand');
    }

    private function inventoryInTransitAccount(): ChartOfAccounts
    {
        return $this->resolveAccount('inventory_in_transit', 'Inventory In Transit');
    }

    private function purchaseExpensesPayableAccount(): ChartOfAccounts
    {
        return $this->resolveAccount('purchase_expenses_payable', 'Purchase Expenses Payable');
    }

    private function shortageLossAccount(): ChartOfAccounts
    {
        return $this->resolveAccount('shortage_loss', 'Shortage / Inventory Loss');
    }

    private function logStatusChange(PurchaseInvoice $invoice, ?string $from, string $to, ?string $remarks = null): void
    {
        PurchaseStatusHistory::create([
            'purchase_invoice_id' => $invoice->id,
            'from_status'         => $from,
            'to_status'           => $to,
            'changed_by'          => Auth::id(),
            'remarks'             => $remarks,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $user  = auth()->user();
        $query = PurchaseInvoice::with(['vendor', 'attachments']);

        if ($request->has('view_deleted')) {
            $query->onlyTrashed();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if (!$user->hasRole('superadmin')) {
            $query->where('created_by', $user->id);
        }

        $invoices = $query->latest()->get();

        return view('purchases.index', compact('invoices'));
    }

    // ─────────────────────────────────────────────────────────────
    // CREATE FORM
    // ─────────────────────────────────────────────────────────────
    public function create()
    {
        $products = Product::with('variations')->orderBy('name')->get();
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units    = MeasurementUnit::all();

        return view('purchases.create', compact('products', 'vendors', 'units'));
    }

    // ─────────────────────────────────────────────────────────────
    // STORE  (Purchase Invoice) — creates a PENDING record only.
    //
    // No inventory, no accounting, no voucher at this stage.
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        Log::info('[PI] Store started', ['user_id' => auth()->id()]);

        $request->validate([
            'invoice_date'          => 'required|date',
            'vendor_id'             => 'required|exists:chart_of_accounts,id',
            'bill_no'               => 'nullable|string|max:100',
            'ref_no'                => 'nullable|string|max:100',
            'remarks'               => 'nullable|string',
            'attachments.*'         => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                 => 'required|array|min:1',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $last      = PurchaseInvoice::withTrashed()->orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $invoice = PurchaseInvoice::create([
                'invoice_no'   => $invoiceNo,
                'vendor_id'    => $request->vendor_id,
                'invoice_date' => $request->invoice_date,
                'bill_no'      => $request->bill_no,
                'ref_no'       => $request->ref_no,
                'remarks'      => $request->remarks,
                'status'       => PurchaseInvoice::STATUS_PENDING,
                'created_by'   => auth()->id(),
            ]);

            Log::info('[PI] Header created (Pending)', ['invoice_id' => $invoice->id, 'invoice_no' => $invoiceNo]);

            $totalAmount   = 0;
            $totalQuantity = 0;

            foreach ($request->items as $itemData) {
                $qty       = (float) ($itemData['quantity'] ?? 0);
                $price     = (float) ($itemData['price']    ?? 0);
                $lineTotal = $qty * $price;
                $totalAmount   += $lineTotal;
                $totalQuantity += $qty;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'],
                    'price'        => $price,
                    // dispatched_quantity / received_quantity stay null —
                    // no inventory movement happens at Pending stage.
                ]);
            }

            $invoice->update([
                'total_amount'   => $totalAmount,
                'total_quantity' => $totalQuantity,
                'net_amount'     => $totalAmount,
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('purchase_invoices', 'public');
                    $invoice->attachments()->create([
                        'file_path'     => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type'     => $file->getClientMimeType(),
                        'stage'         => PurchaseInvoice::STATUS_PENDING,
                    ]);
                }
            }

            $this->logStatusChange($invoice, null, PurchaseInvoice::STATUS_PENDING, 'Purchase Invoice created.');

            DB::commit();
            Log::info('[PI] Stored successfully (Pending)', ['invoice_id' => $invoice->id]);

            return redirect()->route('purchase_invoices.index')
                ->with('success', 'Purchase Invoice created as Pending.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // SHOW  (Purchase Details page)
    // ─────────────────────────────────────────────────────────────
    public function show($id)
    {
        $invoice = PurchaseInvoice::with([
            'vendor', 'items.product', 'items.variation',
            'attachments', 'statusHistories.changedBy',
        ])->findOrFail($id);

        $vouchers = $invoice->vouchers();

        return view('purchases.show', compact('invoice', 'vouchers'));
    }

    // ─────────────────────────────────────────────────────────────
    // EDIT FORM — only while Pending
    // ─────────────────────────────────────────────────────────────
    public function edit($id)
    {
        $invoice = PurchaseInvoice::with(['items.product.variations', 'items.variation', 'attachments'])
                        ->findOrFail($id);

        if (!$invoice->isPending()) {
            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('error', 'Only Pending invoices can be edited.');
        }

        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $products = Product::with('variations')->select('id', 'name', 'measurement_unit')->get();
        $units    = MeasurementUnit::all();

        return view('purchases.edit', compact('invoice', 'vendors', 'products', 'units'));
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE — only while Pending (no accounting/inventory exists yet,
    // so this is a plain header/items rewrite, same as before).
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $request->validate([
            'invoice_date'          => 'required|date',
            'vendor_id'             => 'required|exists:chart_of_accounts,id',
            'bill_no'               => 'nullable|string|max:100',
            'ref_no'                => 'nullable|string|max:100',
            'remarks'               => 'nullable|string',
            'attachments.*'         => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                 => 'required|array|min:1',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.quantity'      => 'required|numeric|min:0.01',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('items')->lockForUpdate()->findOrFail($id);

            if (!$invoice->isPending()) {
                DB::rollBack();
                return back()->withErrors(['error' => 'Only Pending invoices can be edited.']);
            }

            $invoice->update([
                'vendor_id'    => $request->vendor_id,
                'invoice_date' => $request->invoice_date,
                'bill_no'      => $request->bill_no,
                'ref_no'       => $request->ref_no,
                'remarks'      => $request->remarks,
            ]);

            $invoice->items()->delete();
            $totalAmount   = 0;
            $totalQuantity = 0;

            foreach ($request->items as $itemData) {
                if (empty($itemData['item_id'])) continue;

                $qty   = (float) $itemData['quantity'];
                $price = (float) $itemData['price'];
                $totalAmount   += $qty * $price;
                $totalQuantity += $qty;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'] ?? null,
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'] ?? null,
                    'price'        => $price,
                ]);
            }

            $invoice->update([
                'total_amount'   => $totalAmount,
                'total_quantity' => $totalQuantity,
                'net_amount'     => $totalAmount,
            ]);

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('purchase_invoices', 'public');
                    $invoice->attachments()->create([
                        'file_path'     => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type'     => $file->getClientMimeType(),
                        'stage'         => PurchaseInvoice::STATUS_PENDING,
                    ]);
                }
            }

            DB::commit();
            return redirect()->route('purchase_invoices.index')->with('success', 'Purchase Invoice updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // MOVE TO IN TRANSIT
    //
    // Requires: Vendor Bill Number (bill_no), Bilty Number, Attachment.
    // Accounting:  DR Inventory In Transit   CR Vendor (Accounts Payable)
    // ─────────────────────────────────────────────────────────────
    public function moveToInTransit(Request $request, $id)
    {
        $request->validate([
            'bill_no'        => 'required|string|max:100',
            'bilty_no'       => 'required|string|max:100',
            'attachment'     => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'remarks'        => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with(['items', 'attachments'])->lockForUpdate()->findOrFail($id);

            // Idempotency guard: reject if this invoice isn't Pending anymore
            // (handles double-submit, refresh, network retry).
            if (!$invoice->isPending()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not in Pending status — no action taken.');
            }

            $hasAttachment = $request->hasFile('attachment') || $invoice->attachments()->exists();
            if (!$hasAttachment) {
                DB::rollBack();
                return back()->withErrors(['attachment' => 'An attachment (dispatch proof) is required to move to In Transit.']);
            }

            $totalAmount = 0;
            foreach ($invoice->items as $item) {
                $item->update(['dispatched_quantity' => $item->quantity]);
                $totalAmount += $item->quantity * $item->price;
            }

            $invoice->update([
                'bill_no'  => $request->bill_no,
                'bilty_no' => $request->bilty_no,
                'status'   => PurchaseInvoice::STATUS_IN_TRANSIT,
            ]);

            if ($request->hasFile('attachment')) {
                $path = $request->file('attachment')->store('purchase_invoices', 'public');
                $invoice->attachments()->create([
                    'file_path'     => $path,
                    'original_name' => $request->file('attachment')->getClientOriginalName(),
                    'file_type'     => $request->file('attachment')->getClientMimeType(),
                    'stage'         => PurchaseInvoice::STATUS_IN_TRANSIT,
                ]);
            }

            // DR Inventory In Transit / CR Vendor (Accounts Payable)
            Voucher::create([
                'date'         => now()->toDateString(),
                'voucher_type' => 'journal',
                'ac_dr_sid'    => $this->inventoryInTransitAccount()->id,
                'ac_cr_sid'    => $invoice->vendor_id,
                'amount'       => $totalAmount,
                'reference'    => "PI-{$invoice->id}-INTRANSIT",
                'remarks'      => "Purchase Invoice #{$invoice->invoice_no} — goods in transit (vendor payable created)",
            ]);

            $this->logStatusChange($invoice, PurchaseInvoice::STATUS_PENDING, PurchaseInvoice::STATUS_IN_TRANSIT, $request->remarks);

            DB::commit();
            Log::info('[PI] Moved to In Transit', ['invoice_id' => $invoice->id, 'amount' => $totalAmount]);

            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('success', 'Purchase Invoice moved to In Transit. Vendor payable created.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] MoveToInTransit error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // RECEIVE FORM
    // ─────────────────────────────────────────────────────────────
    public function receiveForm($id)
    {
        $invoice = PurchaseInvoice::with(['items.product', 'items.variation', 'vendor'])->findOrFail($id);

        if (!$invoice->isInTransit()) {
            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('error', 'Only invoices In Transit can be received.');
        }

        return view('purchases.receive', compact('invoice'));
    }

    // ─────────────────────────────────────────────────────────────
    // RECEIVE  (In Transit -> Received)
    //
    // Allocation rule for Bilty/Labor/Other charges: equal-per-unit,
    // i.e. spread across total dispatched quantity, then multiplied
    // by each item's received quantity.
    //
    // Accounting:
    //   DR Actual Inventory        CR Inventory In Transit   (received value)
    //   DR Shortage/Loss Account   CR Inventory In Transit   (shortage value, if any)
    //   DR Actual Inventory        CR Purchase Expenses Payable  (bilty+labor+other)
    // ─────────────────────────────────────────────────────────────
    public function receive(Request $request, $id)
    {
        $request->validate([
            'received_date'                  => 'required|date',
            'bilty_charges'                  => 'nullable|numeric|min:0',
            'labor_charges'                  => 'nullable|numeric|min:0',
            'other_charges'                  => 'nullable|numeric|min:0',
            'remarks'                        => 'nullable|string',
            'attachment'                     => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                          => 'required|array|min:1',
            'items.*.id'                     => 'required|exists:purchase_invoice_items,id',
            'items.*.received_quantity'      => 'required|numeric|min:0',
            'items.*.shortage_reason'        => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('items')->lockForUpdate()->findOrFail($id);

            // Idempotency guard
            if (!$invoice->isInTransit()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not In Transit — no action taken.');
            }

            $biltyCharges = (float) ($request->bilty_charges ?? 0);
            $laborCharges = (float) ($request->labor_charges ?? 0);
            $otherCharges = (float) ($request->other_charges ?? 0);
            $totalAdditional = $biltyCharges + $laborCharges + $otherCharges;

            $totalDispatchedQty = $invoice->items->sum(fn ($i) => (float) ($i->dispatched_quantity ?? $i->quantity));
            $perUnitExtra = $totalDispatchedQty > 0 ? ($totalAdditional / $totalDispatchedQty) : 0;

            $totalReceivedValue   = 0;
            $totalDispatchedValue = 0;

            $itemsInput = collect($request->items)->keyBy('id');

            foreach ($invoice->items as $item) {
                $input = $itemsInput->get($item->id);
                if (!$input) continue;

                $dispatchedQty = (float) ($item->dispatched_quantity ?? $item->quantity);
                $receivedQty   = (float) $input['received_quantity'];

                if ($receivedQty > $dispatchedQty) {
                    throw new \Exception("Received quantity for item #{$item->id} cannot exceed dispatched quantity.");
                }

                $shortQty       = max($dispatchedQty - $receivedQty, 0);
                $allocatedExtra = round($receivedQty * $perUnitExtra, 2);

                $item->update([
                    'received_quantity'         => $receivedQty,
                    'short_quantity'             => $shortQty,
                    'shortage_reason'            => $shortQty > 0 ? ($input['shortage_reason'] ?? null) : null,
                    'allocated_additional_cost'  => $allocatedExtra,
                ]);

                $totalReceivedValue   += $receivedQty * $item->price;
                $totalDispatchedValue += $dispatchedQty * $item->price;

                // Physical stock becomes available only now.
                if ($item->variation_id) {
                    $variation = ProductVariation::find($item->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $receivedQty);
                    } else {
                        Log::warning('[PI] Variation not found on receive', ['variation_id' => $item->variation_id]);
                    }
                }
            }

            $shortageValue = round($totalDispatchedValue - $totalReceivedValue, 2);

            $inventoryAccount = $this->inventoryAccount();
            $transitAccount   = $this->inventoryInTransitAccount();

            // 1) Base transfer: DR Actual Inventory / CR Inventory In Transit
            if ($totalReceivedValue > 0) {
                Voucher::create([
                    'date'         => $request->received_date,
                    'voucher_type' => 'journal',
                    'ac_dr_sid'    => $inventoryAccount->id,
                    'ac_cr_sid'    => $transitAccount->id,
                    'amount'       => $totalReceivedValue,
                    'reference'    => "PI-{$invoice->id}-RECEIVE-BASE",
                    'remarks'      => "Purchase Invoice #{$invoice->invoice_no} — goods received into actual inventory",
                ]);
            }

            // 2) Shortage: DR Shortage/Loss / CR Inventory In Transit
            if ($shortageValue > 0) {
                Voucher::create([
                    'date'         => $request->received_date,
                    'voucher_type' => 'journal',
                    'ac_dr_sid'    => $this->shortageLossAccount()->id,
                    'ac_cr_sid'    => $transitAccount->id,
                    'amount'       => $shortageValue,
                    'reference'    => "PI-{$invoice->id}-RECEIVE-SHORTAGE",
                    'remarks'      => "Purchase Invoice #{$invoice->invoice_no} — shortage on receipt",
                ]);
            }

            // 3) Additional receiving costs: DR Actual Inventory / CR Purchase Expenses Payable
            if ($totalAdditional > 0) {
                Voucher::create([
                    'date'         => $request->received_date,
                    'voucher_type' => 'journal',
                    'ac_dr_sid'    => $inventoryAccount->id,
                    'ac_cr_sid'    => $this->purchaseExpensesPayableAccount()->id,
                    'amount'       => $totalAdditional,
                    'reference'    => "PI-{$invoice->id}-RECEIVE-CHARGES",
                    'remarks'      => "Purchase Invoice #{$invoice->invoice_no} — bilty/labor/other charges added to inventory cost",
                ]);
            }

            $invoice->update([
                'status'         => PurchaseInvoice::STATUS_RECEIVED,
                'received_at'    => $request->received_date,
                'received_by'    => auth()->id(),
                'bilty_charges'  => $biltyCharges,
                'labor_charges'  => $laborCharges,
                'other_charges'  => $otherCharges,
            ]);

            if ($request->hasFile('attachment')) {
                $path = $request->file('attachment')->store('purchase_invoices', 'public');
                $invoice->attachments()->create([
                    'file_path'     => $path,
                    'original_name' => $request->file('attachment')->getClientOriginalName(),
                    'file_type'     => $request->file('attachment')->getClientMimeType(),
                    'stage'         => PurchaseInvoice::STATUS_RECEIVED,
                ]);
            }

            $this->logStatusChange($invoice, PurchaseInvoice::STATUS_IN_TRANSIT, PurchaseInvoice::STATUS_RECEIVED, $request->remarks);

            DB::commit();
            Log::info('[PI] Received', [
                'invoice_id' => $invoice->id,
                'received_value' => $totalReceivedValue,
                'shortage_value' => $shortageValue,
                'additional_costs' => $totalAdditional,
            ]);

            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('success', 'Purchase Invoice received. Inventory and accounting updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Receive error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // DESTROY  (soft delete) — only safe while Pending.
    // In Transit / Received have live accounting & inventory impact;
    // those require a proper reversal flow, not a plain delete.
    // ─────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        $invoice = PurchaseInvoice::with('items')->findOrFail($id);

        if (!$invoice->isPending()) {
            return back()->with('error', 'Only Pending invoices can be deleted. In Transit / Received invoices require a controlled reversal.');
        }

        DB::beginTransaction();

        try {
            $invoice->items()->delete();
            $invoice->delete();

            DB::commit();
            return redirect()->route('purchase_invoices.index')
                ->with('success', 'Invoice deleted.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Destroy error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Failed to delete invoice.');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // RESTORE — only meaningful for Pending invoices under this flow.
    // ─────────────────────────────────────────────────────────────
    public function restore($id)
    {
        $invoice = PurchaseInvoice::onlyTrashed()->with('items')->findOrFail($id);

        DB::beginTransaction();

        try {
            $invoice->restore();
            $invoice->items()->onlyTrashed()->get()->each->restore();

            DB::commit();
            return redirect()->back()->with('success', 'Invoice restored.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Restore error', ['message' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Restore failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PRINT  (PDF) — unchanged from the existing implementation,
    // with the status label added to the header block.
    // ─────────────────────────────────────────────────────────────
    public function print($id)
    {
        $invoice = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetAuthor('Lucky Corporation');
        $pdf->SetTitle('PUR-' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $logoPath = public_path('assets/img/logo.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 15, 12, 35);
        }

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(110, 12);
        $pdf->Cell(85, 10, 'PURCHASE INVOICE', 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(110, 20);
        $pdf->Cell(85, 5, 'Invoice #: ' . $invoice->invoice_no, 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Date: ' . Carbon::parse($invoice->invoice_date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Status: ' . $invoice->statusLabel(), 0, 1, 'R');
        $pdf->Ln(5);

        $vendorHtml = '
        <table width="40%" border="1" cellpadding="3" style="font-size:10px;">
            <tr><td width="40%"><b>Vendor:</b></td><td width="60%">' . ($invoice->vendor->name ?? 'N/A') . '</td></tr>
            <tr><td><b>Vendor Bill No:</b></td><td>'  . ($invoice->bill_no ?? '-') . '</td></tr>
            <tr><td><b>Bilty No:</b></td><td>'  . ($invoice->bilty_no ?? '-') . '</td></tr>
            <tr><td><b>Ref:</b></td><td>'       . ($invoice->ref_no  ?? '-') . '</td></tr>
        </table>';
        $pdf->writeHTML($vendorHtml, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '
        <table border="1" cellpadding="5" style="font-size:10px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="5%">#</th>
                    <th width="30%">Item Description</th>
                    <th width="15%">Variation</th>
                    <th width="10%">Qty</th>
                    <th width="10%">Received</th>
                    <th width="15%">Price</th>
                    <th width="15%">Total</th>
                </tr>
            </thead>
            <tbody>';

        $totalAmount = 0;
        foreach ($invoice->items as $index => $item) {
            $variationName = $item->variation->sku ?? $item->variation->variation_name ?? '-';
            $lineTotal      = $item->quantity * $item->price;
            $totalAmount   += $lineTotal;

            $html .= '
                <tr>
                    <td width="5%"  style="text-align:center;">' . ($index + 1) . '</td>
                    <td width="30%">' . e($item->product->name ?? '-') . '</td>
                    <td width="15%" style="text-align:center;">' . e($variationName) . '</td>
                    <td width="10%" style="text-align:center;">' . number_format($item->quantity, 2) . '</td>
                    <td width="10%" style="text-align:center;">' . number_format($item->received_quantity ?? 0, 2) . '</td>
                    <td width="15%" style="text-align:right;">'  . number_format($item->price, 2) . '</td>
                    <td width="15%" style="text-align:right;">'  . number_format($lineTotal, 2) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="font-weight:bold;background-color:#fafafa;">
                    <td colspan="6" style="text-align:right;">Total Amount</td>
                    <td style="text-align:right;">' . number_format($totalAmount, 2) . '</td>
                </tr>
            </tbody>
        </table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        if ($invoice->remarks) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->remarks, 0, 'L');
        }

        $pdf->SetFont('helvetica', '', 10);
        $ySign = $pdf->GetY() + 25;
        if ($ySign > 250) { $pdf->AddPage(); $ySign = 30; }

        $pdf->Line(15, $ySign, 75, $ySign);
        $pdf->SetXY(15, $ySign + 2);
        $pdf->Cell(60, 5, 'Prepared By', 0, 0, 'C');

        $pdf->Line(135, $ySign, 195, $ySign);
        $pdf->SetXY(135, $ySign + 2);
        $pdf->Cell(60, 5, 'Authorized Signature', 0, 0, 'C');

        return $pdf->Output('PI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}
