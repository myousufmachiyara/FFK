<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseInvoiceExpense;
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
    private function resolveAccount(string $configKey, string $label): ChartOfAccounts
    {
        $code = config("purchase_accounts.{$configKey}");
        $account = ChartOfAccounts::where('account_code', $code)->first();

        if (!$account) {
            throw new \Exception("{$label} account (code {$code}) not found. Check config/purchase_accounts.php and your Chart of Accounts.");
        }

        return $account;
    }

    private function inventoryAccount(): ChartOfAccounts             { return $this->resolveAccount('inventory', 'Inventory / Stock in Hand'); }
    private function inventoryInTransitAccount(): ChartOfAccounts    { return $this->resolveAccount('inventory_in_transit', 'Inventory In Transit'); }
    private function shortageLossAccount(): ChartOfAccounts          { return $this->resolveAccount('shortage_loss', 'Shortage / Inventory Loss'); }

    private function kgPerMaund(): int
    {
        return (int) config('purchase_settings.kg_per_maund', 40);
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

    /** Posts a simple DR/CR voucher, auto-flipping legs if the amount would be negative. */
    private function postVoucher(string $date, ChartOfAccounts $dr, ChartOfAccounts $cr, float $amount, string $reference, string $remarks): void
    {
        if (abs($amount) < 0.01) return;

        if ($amount < 0) {
            [$dr, $cr] = [$cr, $dr];
            $amount = abs($amount);
            Log::warning('[PI] Negative amount voucher leg flipped', ['reference' => $reference, 'amount' => $amount]);
        }

        Voucher::create([
            'date'         => $date,
            'voucher_type' => 'journal',
            'ac_dr_sid'    => $dr->id,
            'ac_cr_sid'    => $cr->id,
            'amount'       => round($amount, 2),
            'reference'    => $reference,
            'remarks'      => $remarks,
        ]);
    }

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

    public function create()
    {
        $products = Product::with('variations')->orderBy('name')->get();
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units    = MeasurementUnit::all();
        $payeeAccounts = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get(); // e.g. a specific transporter
        $kgPerMaund = $this->kgPerMaund();

        return view('purchases.create', compact('products', 'vendors', 'units', 'payeeAccounts', 'kgPerMaund'));
    }

    /**
     * Shared server-side item + expense computation for store()/update().
     * NEVER trust client-submitted gross_weight/amount/totals.
     */
    private function syncItemsAndExpenses(PurchaseInvoice $invoice, array $items, array $expenses): array
    {
        $invoice->items()->delete();
        $invoice->expenses()->delete();

        $kgPerMaund = $this->kgPerMaund();
        $totalQty = $totalWeight = $totalGrossWeight = $totalAmount = 0;

        foreach ($items as $itemData) {
            $qty          = (float) $itemData['quantity'];
            $wtPerPacking = (float) $itemData['wt_per_packing'];
            $netOverride  = isset($itemData['net_weight']) && $itemData['net_weight'] !== ''
                ? (float) $itemData['net_weight'] : null;
            $ratePer40kg  = (float) $itemData['rate_per_40kg'];

            $calc = PurchaseInvoiceItem::computeLine($wtPerPacking, $qty, $netOverride, $ratePer40kg, $kgPerMaund);

            $invoice->items()->create([
                'item_id'         => $itemData['item_id'],
                'variation_id'    => $itemData['variation_id'] ?? null,
                'packing_unit_id' => $itemData['packing_unit_id'] ?? null,
                'wt_per_packing'  => $wtPerPacking,
                'quantity'        => $qty,
                'gross_weight'    => $calc['grossWeight'],
                'net_weight'      => $calc['netWeight'],
                'rate_per_40kg'   => $ratePer40kg,
                'price'           => $calc['ratePerKg'],
                'amount'          => $calc['amount'],
            ]);

            $totalQty         += $qty;
            $totalWeight       += $calc['netWeight'];
            $totalGrossWeight  += $calc['grossWeight'];
            $totalAmount       += $calc['amount'];
        }

        $totalOtherExpenses = 0;
        foreach ($expenses as $expenseData) {
            if (empty($expenseData['amount'])) continue;
            $amount = (float) $expenseData['amount'];
            $totalOtherExpenses += $amount;

            $invoice->expenses()->create([
                'expense_type'      => $expenseData['expense_type'],
                'description'       => $expenseData['description'] ?? null,
                'amount'            => $amount,
                'paid_by'           => $expenseData['paid_by'],
                'payee_account_id'  => $expenseData['paid_by'] === 'company' ? ($expenseData['payee_account_id'] ?? null) : null,
            ]);
        }

        return [
            'total_quantity'         => $totalQty,
            'total_weight'           => round($totalWeight, 3),
            'total_gross_weight'     => round($totalGrossWeight, 3),
            'total_amount'           => round($totalAmount, 2),
            'total_other_expenses'   => round($totalOtherExpenses, 2),
            'net_amount'             => round($totalAmount, 2),
        ];
    }

    public function store(Request $request)
    {
        Log::info('[PI] Store started', ['user_id' => auth()->id()]);

        $request->validate([
            'invoice_date'                 => 'required|date',
            'vendor_id'                    => 'required|exists:chart_of_accounts,id',
            'bill_no'                      => 'nullable|string|max:100',
            'bilty_no'                     => 'nullable|string|max:100',
            'transport_name'               => 'nullable|string|max:150',
            'ref_no'                       => 'nullable|string|max:100',
            'remarks'                      => 'nullable|string',
            'payment_terms'                => 'required|in:cash,credit',
            'credit_days'                  => 'required_if:payment_terms,credit|nullable|integer|min:1',
            'attachments.*'                => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                        => 'required|array|min:1',
            'items.*.item_id'              => 'required|exists:products,id',
            'items.*.variation_id'         => 'nullable|exists:product_variations,id',
            'items.*.packing_unit_id'      => 'nullable|exists:measurement_units,id',
            'items.*.wt_per_packing'       => 'required|numeric|min:0.001',
            'items.*.quantity'             => 'required|numeric|min:0.01',
            'items.*.net_weight'           => 'nullable|numeric|min:0',
            'items.*.rate_per_40kg'        => 'required|numeric|min:0',
            'expenses'                     => 'nullable|array',
            'expenses.*.expense_type'      => 'required_with:expenses|in:local_cartage,packaging,plastic_bags,bardana,misc,tulai,others',
            'expenses.*.description'       => 'nullable|string|max:255',
            'expenses.*.amount'            => 'required_with:expenses|numeric|min:0',
            'expenses.*.paid_by'           => 'required_with:expenses|in:vendor,company',
            'expenses.*.payee_account_id'  => 'nullable|exists:chart_of_accounts,id',
        ]);

        // Company-paid expenses must have a payee selected.
        foreach ($request->expenses ?? [] as $i => $exp) {
            if (($exp['paid_by'] ?? null) === 'company' && empty($exp['payee_account_id'])) {
                return back()->withInput()->withErrors(["expenses.$i.payee_account_id" => 'Select which account is being paid.']);
            }
        }

        DB::beginTransaction();

        try {
            $last      = PurchaseInvoice::withTrashed()->orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $invoice = PurchaseInvoice::create([
                'invoice_no'      => $invoiceNo,
                'vendor_id'       => $request->vendor_id,
                'invoice_date'    => $request->invoice_date,
                'bill_no'         => $request->bill_no,
                'bilty_no'        => $request->bilty_no,
                'transport_name'  => $request->transport_name,
                'ref_no'          => $request->ref_no,
                'remarks'         => $request->remarks,
                'payment_terms'   => $request->payment_terms,
                'credit_days'     => $request->payment_terms === 'credit' ? $request->credit_days : null,
                'status'          => PurchaseInvoice::STATUS_PENDING,
                'created_by'      => auth()->id(),
            ]);

            $totals = $this->syncItemsAndExpenses($invoice, $request->items, $request->expenses ?? []);
            $invoice->update($totals);

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

    public function show($id)
    {
        $invoice = PurchaseInvoice::with([
            'vendor', 'items.product', 'items.variation', 'items.packingUnit',
            'expenses.payeeAccount', 'attachments', 'statusHistories.changedBy',
        ])->findOrFail($id);

        $vouchers = $invoice->vouchers();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get();

        return view('purchases.show', compact('invoice', 'vouchers', 'paymentAccounts'));
    }

    public function edit($id)
    {
        $invoice = PurchaseInvoice::with(['items.product.variations', 'items.variation', 'items.packingUnit', 'expenses', 'attachments'])
                        ->findOrFail($id);

        if (!$invoice->isPending()) {
            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('error', 'Only Pending invoices can be edited.');
        }

        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $products = Product::with('variations')->select('id', 'name', 'measurement_unit')->get();
        $units    = MeasurementUnit::all();
        $payeeAccounts = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $kgPerMaund = $this->kgPerMaund();

        return view('purchases.edit', compact('invoice', 'vendors', 'products', 'units', 'payeeAccounts', 'kgPerMaund'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'invoice_date'                 => 'required|date',
            'vendor_id'                    => 'required|exists:chart_of_accounts,id',
            'bill_no'                      => 'nullable|string|max:100',
            'bilty_no'                     => 'nullable|string|max:100',
            'transport_name'               => 'nullable|string|max:150',
            'ref_no'                       => 'nullable|string|max:100',
            'remarks'                      => 'nullable|string',
            'payment_terms'                => 'required|in:cash,credit',
            'credit_days'                  => 'required_if:payment_terms,credit|nullable|integer|min:1',
            'attachments.*'                => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items'                        => 'required|array|min:1',
            'items.*.item_id'              => 'required|exists:products,id',
            'items.*.variation_id'         => 'nullable|exists:product_variations,id',
            'items.*.packing_unit_id'      => 'nullable|exists:measurement_units,id',
            'items.*.wt_per_packing'       => 'required|numeric|min:0.001',
            'items.*.quantity'             => 'required|numeric|min:0.01',
            'items.*.net_weight'           => 'nullable|numeric|min:0',
            'items.*.rate_per_40kg'        => 'required|numeric|min:0',
            'expenses'                     => 'nullable|array',
            'expenses.*.expense_type'      => 'required_with:expenses|in:local_cartage,packaging,plastic_bags,bardana,misc,tulai,others',
            'expenses.*.description'       => 'nullable|string|max:255',
            'expenses.*.amount'            => 'required_with:expenses|numeric|min:0',
            'expenses.*.paid_by'           => 'required_with:expenses|in:vendor,company',
            'expenses.*.payee_account_id'  => 'nullable|exists:chart_of_accounts,id',
        ]);

        foreach ($request->expenses ?? [] as $i => $exp) {
            if (($exp['paid_by'] ?? null) === 'company' && empty($exp['payee_account_id'])) {
                return back()->withInput()->withErrors(["expenses.$i.payee_account_id" => 'Select which account is being paid.']);
            }
        }

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('items')->lockForUpdate()->findOrFail($id);

            if (!$invoice->isPending()) {
                DB::rollBack();
                return back()->withErrors(['error' => 'Only Pending invoices can be edited.']);
            }

            $invoice->update([
                'vendor_id'       => $request->vendor_id,
                'invoice_date'    => $request->invoice_date,
                'bill_no'         => $request->bill_no,
                'bilty_no'        => $request->bilty_no,
                'transport_name'  => $request->transport_name,
                'ref_no'          => $request->ref_no,
                'remarks'         => $request->remarks,
                'payment_terms'   => $request->payment_terms,
                'credit_days'     => $request->payment_terms === 'credit' ? $request->credit_days : null,
            ]);

            $totals = $this->syncItemsAndExpenses($invoice, $request->items, $request->expenses ?? []);
            $invoice->update($totals);

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

    public function moveToInTransit(Request $request, $id)
    {
        $request->validate([
            'bill_no'         => 'required|string|max:100',
            'bilty_no'        => 'required|string|max:100',
            'transport_name'  => 'required|string|max:150',
            'attachment'      => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'remarks'         => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with(['items', 'attachments'])->lockForUpdate()->findOrFail($id);

            if (!$invoice->isPending()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not in Pending status — no action taken.');
            }

            $hasAttachment = $request->hasFile('attachment') || $invoice->attachments()->exists();
            if (!$hasAttachment) {
                DB::rollBack();
                return back()->withErrors(['attachment' => 'An attachment (dispatch proof) is required to move to In Transit.']);
            }

            foreach ($invoice->items as $item) {
                $item->update(['dispatched_quantity' => $item->quantity]);
            }

            $invoice->update([
                'bill_no'        => $request->bill_no,
                'bilty_no'       => $request->bilty_no,
                'transport_name' => $request->transport_name,
                'status'         => PurchaseInvoice::STATUS_IN_TRANSIT,
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

            $this->postVoucher(
                now()->toDateString(),
                $this->inventoryInTransitAccount(),
                $invoice->vendor,
                (float) $invoice->total_amount,
                "PI-{$invoice->id}-INTRANSIT",
                "Purchase Invoice #{$invoice->invoice_no} — goods in transit (vendor payable created)"
            );

            $this->logStatusChange($invoice, PurchaseInvoice::STATUS_PENDING, PurchaseInvoice::STATUS_IN_TRANSIT, $request->remarks);

            DB::commit();
            Log::info('[PI] Moved to In Transit', ['invoice_id' => $invoice->id, 'amount' => $invoice->total_amount]);

            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('success', 'Purchase Invoice moved to In Transit. Vendor payable created.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] MoveToInTransit error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function revertToPending(Request $request, $id)
    {
        $request->validate(['remarks' => 'nullable|string']);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::lockForUpdate()->findOrFail($id);

            if (!$invoice->isInTransit()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not In Transit — nothing to revert.');
            }

            Voucher::where('reference', "PI-{$invoice->id}-INTRANSIT")->delete();
            $invoice->update(['status' => PurchaseInvoice::STATUS_PENDING]);

            $this->logStatusChange(
                $invoice, PurchaseInvoice::STATUS_IN_TRANSIT, PurchaseInvoice::STATUS_PENDING,
                'Reverted from In Transit (mistaken dispatch). ' . ($request->remarks ?? '')
            );

            DB::commit();
            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('success', 'Dispatch reverted. Invoice is back to Pending and the vendor payable voucher was removed.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] RevertToPending error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function receiveForm($id)
    {
        $invoice = PurchaseInvoice::with(['items.product', 'items.variation', 'items.packingUnit', 'expenses.payeeAccount', 'vendor'])->findOrFail($id);

        if (!$invoice->isInTransit()) {
            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('error', 'Only invoices In Transit can be received.');
        }

        $paymentAccounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get();

        return view('purchases.receive', compact('invoice', 'paymentAccounts'));
    }

    // ─────────────────────────────────────────────────────────────
    // RECEIVE  (In Transit -> Received)
    //
    // Other Expenses were already entered at creation — nothing new is
    // collected here. This step confirms received weight per item,
    // posts the accounting, and optionally records an immediate payment
    // to the vendor now that the full bill amount (items + expenses) is
    // finally known:
    //
    //   DR Actual Inventory        CR Inventory In Transit   (received value, by weight)
    //   DR Shortage/Loss Account   CR Inventory In Transit   (shortage value, if any)
    //   DR Actual Inventory        CR (Vendor OR Payee)      (per expense — paid_by routed)
    //   DR Vendor                  CR Payment Account        (optional immediate payment)
    // ─────────────────────────────────────────────────────────────
    public function receive(Request $request, $id)
    {
        $request->validate([
            'received_date'                     => 'required|date',
            'remarks'                           => 'nullable|string',
            'attachment'                        => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'payment_account_id'                => 'nullable|exists:chart_of_accounts,id',
            'amount_paid'                       => 'nullable|numeric|min:0',
            'items'                              => 'required|array|min:1',
            'items.*.id'                          => 'required|exists:purchase_invoice_items,id',
            'items.*.received_packing_qty'         => 'required|numeric|min:0',
            'items.*.received_net_weight'           => 'required|numeric|min:0',
            'items.*.shortage_reason'                => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with(['items', 'expenses'])->lockForUpdate()->findOrFail($id);


            if (!$invoice->isInTransit()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not In Transit — no action taken.');
            }

            $totalDispatchedWeight = $invoice->items->sum(fn ($i) => (float) $i->net_weight);
            $totalOtherExpenses    = (float) $invoice->total_other_expenses;
            $perKgExtra = $totalDispatchedWeight > 0 ? ($totalOtherExpenses / $totalDispatchedWeight) : 0;

            $totalReceivedValue   = 0;
            $totalDispatchedValue = 0;

            $itemsInput = collect($request->items)->keyBy('id');

            foreach ($invoice->items as $item) {
                $input = $itemsInput->get($item->id);
                if (!$input) continue;

                $dispatchedWeight   = (float) $item->net_weight;
                $receivedWeight     = (float) $input['received_net_weight'];
                $dispatchedQty      = (float) $item->quantity;
                $receivedQty        = (float) $input['received_packing_qty'];

                if ($receivedWeight > $dispatchedWeight) {
                    throw new \Exception("Received weight for item #{$item->id} cannot exceed dispatched net weight ({$dispatchedWeight} kg).");
                }
                if ($receivedQty > $dispatchedQty) {
                    throw new \Exception("Received bags for item #{$item->id} cannot exceed dispatched quantity ({$dispatchedQty} bags).");
                }

                $shortWeight    = max($dispatchedWeight - $receivedWeight, 0);
                $allocatedExtra = round($receivedWeight * $perKgExtra, 2);

                $item->update([
                    'received_packing_qty'      => $receivedQty,
                    'received_net_weight'       => $receivedWeight,
                    'short_weight'               => $shortWeight,
                    'shortage_reason'            => $shortWeight > 0 ? ($input['shortage_reason'] ?? null) : null,
                    'allocated_additional_cost'  => $allocatedExtra,
                ]);

                $totalReceivedValue   += $receivedWeight * (float) $item->price;
                $totalDispatchedValue += $dispatchedWeight * (float) $item->price;

                // FIX: stock is now tracked by QUANTITY (bags), not
                // weight — 'quantity' is the count that gets sold and
                // deducted at Sale time, matching how the business
                // actually thinks about inventory (bags in, bags out).
                // 'stock_weight' is maintained alongside purely as the
                // net-weight-in-stock figure for display.
                if ($item->variation_id) {
                    $variation = ProductVariation::find($item->variation_id);
                    if ($variation) {
                        $variation->increment('stock_quantity', $receivedQty);
                        $variation->increment('stock_weight', $receivedWeight);
                    } else {
                        Log::warning('[PI] Variation not found on receive', ['variation_id' => $item->variation_id]);
                    }
                }
            }

            $shortageValue = round($totalDispatchedValue - $totalReceivedValue, 2);

            $inventoryAccount = $this->inventoryAccount();
            $transitAccount   = $this->inventoryInTransitAccount();

            if ($totalReceivedValue > 0) {
                $this->postVoucher(
                    $request->received_date, $inventoryAccount, $transitAccount, $totalReceivedValue,
                    "PI-{$invoice->id}-RECEIVE-BASE",
                    "Purchase Invoice #{$invoice->invoice_no} — goods received into actual inventory (by weight)"
                );
            }

            if ($shortageValue > 0) {
                $this->postVoucher(
                    $request->received_date, $this->shortageLossAccount(), $transitAccount, $shortageValue,
                    "PI-{$invoice->id}-RECEIVE-SHORTAGE",
                    "Purchase Invoice #{$invoice->invoice_no} — weight shortage on receipt"
                );
            }

            // Each expense: already stored at creation, routed to Vendor or
            // the specific payee account chosen at that time.
            foreach ($invoice->expenses as $i => $expense) {
                $targetAccount = $expense->paid_by === PurchaseInvoiceExpense::PAID_BY_VENDOR
                    ? $invoice->vendor
                    : $expense->payeeAccount;

                if (!$targetAccount) {
                    throw new \Exception("Expense #{$expense->id} ({$expense->typeLabel()}) has no valid payee account.");
                }

                $this->postVoucher(
                    $request->received_date, $inventoryAccount, $targetAccount, (float) $expense->amount,
                    "PI-{$invoice->id}-RECEIVE-EXPENSE-" . ($i + 1),
                    "Purchase Invoice #{$invoice->invoice_no} — {$expense->typeLabel()}, paid by {$expense->paidByLabel()}"
                );
            }

            $invoice->update([
                'status'       => PurchaseInvoice::STATUS_RECEIVED,
                'received_at'  => $request->received_date,
                'received_by'  => auth()->id(),
            ]);

            // Optional immediate payment to the vendor — now that
            // totalBillAmount() (items + expenses) is finally known.
            $amountPaidNow = (float) ($request->amount_paid ?? 0);
            if ($amountPaidNow > 0) {
                if (!$request->payment_account_id) {
                    throw new \Exception('A payment account is required to record a payment.');
                }
                if ($amountPaidNow > $invoice->totalBillAmount()) {
                    throw new \Exception('Amount paid cannot exceed the total bill amount.');
                }

                $this->postVoucher(
                    $request->received_date, $invoice->vendor, ChartOfAccounts::findOrFail($request->payment_account_id), $amountPaidNow,
                    "PI-{$invoice->id}-PAYMENT-1",
                    "Purchase Invoice #{$invoice->invoice_no} — payment to vendor"
                );

                $invoice->update(['amount_paid' => $amountPaidNow]);
            }

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
                'other_expenses' => $totalOtherExpenses,
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
    // UNDO RECEIVE — Received -> In Transit. Reverses everything
    // receive() posted: the base transfer, shortage, and per-expense
    // vouchers; decrements stock back by whatever was added; clears
    // each item's received/short fields so receive() can be redone
    // cleanly. Use this for a mistaken receipt, not for correcting a
    // typo — re-run Receive Goods afterward with the right figures.
    // ─────────────────────────────────────────────────────────────
    public function revertToInTransit(Request $request, $id)
    {
        $request->validate(['remarks' => 'nullable|string']);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('items')->lockForUpdate()->findOrFail($id);

            if (!$invoice->isReceived()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not Received — nothing to undo.');
            }

            if ((float) $invoice->amount_paid > 0) {
                DB::rollBack();
                return back()->with('error', 'A payment has already been recorded against this invoice (' . number_format($invoice->amount_paid, 2) . '). Undoing the receipt would leave that payment orphaned — this does not reverse a real bank transaction automatically, so it is blocked. Contact an admin if this genuinely needs correcting.');
            }

            foreach ($invoice->items as $item) {
                if ($item->variation_id && $item->received_net_weight) {
                    $variation = ProductVariation::find($item->variation_id);
                    if ($variation) {
                        $variation->decrement('stock_quantity', (float) $item->received_packing_qty);
                        $variation->decrement('stock_weight', (float) $item->received_net_weight);
                    }
                }

                $item->update([
                    'received_packing_qty'      => null,
                    'received_net_weight'       => null,
                    'short_weight'               => 0,
                    'shortage_reason'            => null,
                    'allocated_additional_cost'  => 0,
                ]);
            }

            Voucher::where('reference', 'like', "PI-{$invoice->id}-RECEIVE-%")->delete();

            $invoice->update([
                'status'       => PurchaseInvoice::STATUS_IN_TRANSIT,
                'received_at'  => null,
                'received_by'  => null,
            ]);

            $this->logStatusChange(
                $invoice, PurchaseInvoice::STATUS_RECEIVED, PurchaseInvoice::STATUS_IN_TRANSIT,
                'Reverted from Received (mistaken receipt). ' . ($request->remarks ?? '')
            );

            DB::commit();
            Log::info('[PI] Reverted to In Transit', ['invoice_id' => $invoice->id]);

            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('success', 'Receipt reverted. Invoice is back to In Transit, stock and vouchers reversed.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] RevertToInTransit error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // ADD PAYMENT — records an additional payment to the vendor after
    // Receiving. Only valid once Received (that's when totalBillAmount()
    // is finalized).
    // ─────────────────────────────────────────────────────────────
    public function addPayment(Request $request, $id)
    {
        $request->validate([
            'payment_date'       => 'required|date',
            'payment_account_id' => 'required|exists:chart_of_accounts,id',
            'amount'             => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('vendor')->lockForUpdate()->findOrFail($id);

            if (!$invoice->isReceived()) {
                DB::rollBack();
                return back()->with('error', 'Payments can only be recorded once the invoice is Received.');
            }

            $remaining = $invoice->remainingBalance();
            $amount = (float) $request->amount;

            if ($amount > $remaining) {
                DB::rollBack();
                return back()->withErrors(['amount' => "Amount cannot exceed the remaining balance ({$remaining})."]);
            }

            $paymentCount = Voucher::where('reference', 'like', "PI-{$invoice->id}-PAYMENT-%")->count();

            $this->postVoucher(
                $request->payment_date, $invoice->vendor, ChartOfAccounts::findOrFail($request->payment_account_id), $amount,
                "PI-{$invoice->id}-PAYMENT-" . ($paymentCount + 1),
                "Purchase Invoice #{$invoice->invoice_no} — additional payment to vendor"
            );

            $invoice->update(['amount_paid' => (float) $invoice->amount_paid + $amount]);

            DB::commit();
            return redirect()->route('purchase_invoices.show', $invoice->id)
                ->with('success', 'Payment recorded.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] AddPayment error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        $invoice = PurchaseInvoice::with('items')->findOrFail($id);

        if (!$invoice->isPending()) {
            return back()->with('error', 'Only Pending invoices can be deleted. In Transit / Received invoices require a controlled reversal.');
        }

        DB::beginTransaction();
        try {
            $invoice->items()->delete();
            $invoice->expenses()->delete();
            $invoice->delete();

            DB::commit();
            return redirect()->route('purchase_invoices.index')->with('success', 'Invoice deleted.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[PI] Destroy error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Failed to delete invoice.');
        }
    }

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

    public function print($id)
    {
        $invoice = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation', 'expenses'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetAuthor('FFK');
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
            <tr><td><b>Transport:</b></td><td>'  . ($invoice->transport_name ?? '-') . '</td></tr>
            <tr><td><b>Ref:</b></td><td>'       . ($invoice->ref_no  ?? '-') . '</td></tr>
        </table>';
        $pdf->writeHTML($vendorHtml, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '
        <table border="1" cellpadding="4" style="font-size:9px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="18%">Item</th>
                    <th width="10%">Variation</th>
                    <th width="10%">Wt/Packing</th>
                    <th width="7%">Qty</th>
                    <th width="10%">Gross Wt</th>
                    <th width="10%">Net Wt</th>
                    <th width="12%">Rate/40kg</th>
                    <th width="11%">Rate/kg</th>
                    <th width="12%">Amount</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($invoice->items as $index => $item) {
            $variationName = $item->variation->sku ?? '-';

            $html .= '
                <tr>
                    <td width="18%">' . e($item->product->name ?? '-') . '</td>
                    <td width="10%" style="text-align:center;">' . e($variationName) . '</td>
                    <td width="10%" style="text-align:right;">' . number_format($item->wt_per_packing, 2) . '</td>
                    <td width="7%" style="text-align:center;">' . number_format($item->quantity, 0) . '</td>
                    <td width="10%" style="text-align:right;">' . number_format($item->gross_weight, 2) . '</td>
                    <td width="10%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                    <td width="12%" style="text-align:right;">' . number_format($item->rate_per_40kg, 2) . '</td>
                    <td width="11%" style="text-align:right;">' . number_format($item->price, 2) . '</td>
                    <td width="12%" style="text-align:right;">' . number_format($item->amount, 2) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="font-weight:bold;background-color:#fafafa;">
                    <td colspan="8" style="text-align:right;">Total Item Amount</td>
                    <td style="text-align:right;">' . number_format($invoice->total_amount, 2) . '</td>
                </tr>
            </tbody>
        </table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        if ($invoice->expenses->count()) {
            $pdf->Ln(3);
            $expHtml = '<table border="1" cellpadding="4" style="font-size:9px;"><thead><tr style="background-color:#f2f2f2;font-weight:bold;"><th width="25%">Expense</th><th width="35%">Description</th><th width="20%">Paid By</th><th width="20%">Amount</th></tr></thead><tbody>';
            foreach ($invoice->expenses as $exp) {
                $expHtml .= '<tr><td width="25%">' . $exp->typeLabel() . '</td><td width="35%">' . e($exp->description) . '</td><td width="20%">' . $exp->paidByLabel() . '</td><td width="20%" style="text-align:right;">' . number_format($exp->amount, 2) . '</td></tr>';
            }
            $expHtml .= '<tr style="font-weight:bold;"><td colspan="3" style="text-align:right;">Total Expense Amount</td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>';
            $expHtml .= '</tbody></table>';
            $pdf->writeHTML($expHtml, true, false, false, false, '');
        }

        $pdf->Ln(3);
        $summaryHtml = '
        <table width="60%" border="1" cellpadding="4" style="font-size:10px;" align="right">
            <tr><td><b>Gross Weight</b></td><td style="text-align:right;">' . number_format($invoice->total_gross_weight, 2) . ' kg</td></tr>
            <tr><td><b>Net Weight</b></td><td style="text-align:right;">' . number_format($invoice->total_weight, 2) . ' kg</td></tr>
            <tr><td><b>Total Item Amount</b></td><td style="text-align:right;">' . number_format($invoice->total_amount, 2) . '</td></tr>
            <tr><td><b>Total Expense Amount</b></td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#fafafa;"><td>Total Bill Amount</td><td style="text-align:right;">' . number_format($invoice->totalBillAmount(), 2) . '</td></tr>
        </table>';
        $pdf->writeHTML($summaryHtml, true, false, false, false, '');

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