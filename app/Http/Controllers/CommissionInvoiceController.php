<?php

namespace App\Http\Controllers;

use App\Models\CommissionInvoice;
use App\Models\CommissionInvoiceItem;
use App\Models\CommissionInvoiceExpense;
use App\Models\CommissionInvoiceAttachment;
use App\Models\CommissionStatusHistory;
use App\Models\Product;
use App\Models\MeasurementUnit;
use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CommissionInvoiceController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Account resolution — via config + Chart of Accounts, never hardcoded.
    // ─────────────────────────────────────────────────────────────
    private function resolveAccount(string $configKey, string $label): ChartOfAccounts
    {
        $code = config("commission_accounts.{$configKey}");
        $account = ChartOfAccounts::where('account_code', $code)->first();

        if (!$account) {
            throw new \Exception("{$label} account (code {$code}) not found. Check config/commission_accounts.php and your Chart of Accounts.");
        }

        return $account;
    }

    private function commissionGoodsInTransitAccount(): ChartOfAccounts { return $this->resolveAccount('commission_goods_in_transit', 'Commission Goods In Transit'); }
    private function commissionIncomeAccount(): ChartOfAccounts        { return $this->resolveAccount('commission_income', 'Commission Income'); }
    private function otherIncomeAccount(): ChartOfAccounts             { return $this->resolveAccount('other_income', 'Other Income (Expense Reimbursement)'); }
    private function commissionClearingAccount(): ChartOfAccounts      { return $this->resolveAccount('commission_clearing', 'Commission Clearing'); }

    private function logStatusChange(CommissionInvoice $invoice, ?string $from, string $to, ?string $remarks = null): void
    {
        CommissionStatusHistory::create([
            'commission_invoice_id' => $invoice->id,
            'from_status'           => $from,
            'to_status'             => $to,
            'changed_by'            => Auth::id(),
            'remarks'               => $remarks,
        ]);
    }

    /** Posts a simple DR/CR voucher, auto-flipping legs if the amount would be negative. */
    private function postVoucher(string $date, ChartOfAccounts $normalDr, ChartOfAccounts $normalCr, float $amount, string $reference, string $remarks): void
    {
        if (abs($amount) < 0.01) {
            return; // nothing to post
        }

        $dr = $normalDr;
        $cr = $normalCr;

        if ($amount < 0) {
            // Unusual: e.g. commission exceeds sale total. Flip legs, log it — don't hide it.
            [$dr, $cr] = [$normalCr, $normalDr];
            $amount = abs($amount);
            Log::warning('[CI] Negative amount voucher leg flipped', ['reference' => $reference, 'amount' => $amount]);
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

    // ─────────────────────────────────────────────────────────────
    // INDEX
    // ─────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $user  = auth()->user();
        $query = CommissionInvoice::with(['vendor', 'customer', 'attachments']);

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

        return view('commissions.index', compact('invoices'));
    }

    // ─────────────────────────────────────────────────────────────
    // CREATE FORM
    // ─────────────────────────────────────────────────────────────
    public function create()
    {
        $products  = Product::with('variations')->orderBy('name')->get();
        $vendors   = ChartOfAccounts::where('account_type', config('commission_accounts.vendor_account_type'))->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', config('commission_accounts.customer_account_type'))->orderBy('name')->get();
        $units     = MeasurementUnit::all();

        return view('commissions.create', compact('products', 'vendors', 'customers', 'units'));
    }

    // ─────────────────────────────────────────────────────────────
    // STORE — creates a PENDING record only. No accounting posted.
    // ─────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'invoice_date'                    => 'required|date',
            'vendor_id'                       => 'required|exists:chart_of_accounts,id',
            'customer_id'                     => 'required|exists:chart_of_accounts,id',
            'transport_name'                  => 'nullable|string|max:150',
            'bilty_no'                        => 'nullable|string|max:100',
            'vendor_bill_no'                  => 'nullable|string|max:100',
            'ref_no'                          => 'nullable|string|max:100',
            'remarks'                         => 'nullable|string',
            'items'                           => 'required|array|min:1',
            'items.*.product_id'              => 'required|exists:products,id',
            'items.*.variation_id'            => 'nullable|exists:product_variations,id',
            'items.*.unit_id'                 => 'nullable|exists:measurement_units,id',
            'items.*.quantity'                => 'required|numeric|min:0.01',
            'items.*.weight'                  => 'nullable|numeric|min:0',
            'items.*.purchase_price'          => 'required|numeric|min:0',
            'items.*.sale_price'              => 'required|numeric|min:0',
            'items.*.commission_percentage'   => 'required|numeric|min:0|max:100',
            'expenses'                        => 'nullable|array',
            'expenses.*.expense_type'         => 'required_with:expenses|in:packing,local_cartage,misc',
            'expenses.*.description'          => 'nullable|string|max:255',
            'expenses.*.amount'               => 'required_with:expenses|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $last      = CommissionInvoice::withTrashed()->orderByDesc('id')->first();
            $invoiceNo = str_pad($last ? intval($last->invoice_no) + 1 : 1, 6, '0', STR_PAD_LEFT);

            $invoice = CommissionInvoice::create([
                'invoice_no'      => $invoiceNo,
                'invoice_date'    => $request->invoice_date,
                'vendor_id'       => $request->vendor_id,
                'customer_id'     => $request->customer_id,
                'transport_name'  => $request->transport_name,
                'bilty_no'        => $request->bilty_no,
                'vendor_bill_no'  => $request->vendor_bill_no,
                'ref_no'          => $request->ref_no,
                'remarks'         => $request->remarks,
                'status'          => CommissionInvoice::STATUS_PENDING,
                'created_by'      => auth()->id(),
            ]);

            $totals = $this->syncItemsAndExpenses($invoice, $request->items, $request->expenses ?? []);
            $invoice->update($totals);

            $this->logStatusChange($invoice, null, CommissionInvoice::STATUS_PENDING, 'Commission Invoice created.');

            DB::commit();
            Log::info('[CI] Stored successfully (Pending)', ['invoice_id' => $invoice->id]);

            return redirect()->route('commission_invoices.index')->with('success', 'Commission Invoice created as Pending.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /** Shared item/expense (re)write + server-side total computation. Used by store() and update(). */
    private function syncItemsAndExpenses(CommissionInvoice $invoice, array $items, array $expenses): array
    {
        $invoice->items()->delete();
        $invoice->expenses()->delete();

        $totalQty = $totalWeight = $totalPurchase = $totalSale = $totalCommission = 0;

        foreach ($items as $itemData) {
            $qty    = (float) $itemData['quantity'];
            $weight = (float) ($itemData['weight'] ?? 0);
            $pPrice = (float) $itemData['purchase_price'];
            $sPrice = (float) $itemData['sale_price'];
            $comPct = (float) $itemData['commission_percentage'];

            $calc = CommissionInvoiceItem::computeLine($qty, $pPrice, $sPrice, $comPct);

            $invoice->items()->create([
                'product_id'             => $itemData['product_id'],
                'variation_id'           => $itemData['variation_id'] ?? null,
                'unit_id'                => $itemData['unit_id'] ?? null,
                'quantity'               => $qty,
                'weight'                 => $weight,
                'purchase_price'         => $pPrice,
                'sale_price'             => $sPrice,
                'commission_percentage'  => $comPct,
                'commission_amount'      => $calc['commissionAmount'],
                'purchase_total'         => $calc['purchaseTotal'],
                'sale_total'             => $calc['saleTotal'],
            ]);

            $totalQty        += $qty;
            $totalWeight     += $weight;
            $totalPurchase   += $calc['purchaseTotal'];
            $totalSale       += $calc['saleTotal'];
            $totalCommission += $calc['commissionAmount'];
        }

        $totalOtherExpenses = 0;
        foreach ($expenses as $expenseData) {
            if (empty($expenseData['amount'])) continue;
            $amount = (float) $expenseData['amount'];
            $totalOtherExpenses += $amount;

            $invoice->expenses()->create([
                'expense_type' => $expenseData['expense_type'],
                'description'  => $expenseData['description'] ?? null,
                'amount'       => $amount,
            ]);
        }

        return [
            'total_quantity'           => $totalQty,
            'total_weight'             => $totalWeight,
            'total_purchase_amount'    => round($totalPurchase, 2),
            'total_sale_amount'        => round($totalSale, 2),
            'total_commission_amount'  => round($totalCommission, 2),
            'total_other_expenses'     => round($totalOtherExpenses, 2),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // SHOW (Details page)
    // ─────────────────────────────────────────────────────────────
    public function show($id)
    {
        $invoice = CommissionInvoice::with([
            'vendor', 'customer', 'items.product', 'items.variation', 'items.unit',
            'expenses', 'attachments', 'statusHistories.changedBy',
        ])->findOrFail($id);

        $vouchers = $invoice->vouchers();

        return view('commissions.show', compact('invoice', 'vouchers'));
    }

    // ─────────────────────────────────────────────────────────────
    // EDIT FORM — only while Pending
    // ─────────────────────────────────────────────────────────────
    public function edit($id)
    {
        $invoice = CommissionInvoice::with(['items', 'expenses', 'attachments'])->findOrFail($id);

        if (!$invoice->isPending()) {
            return redirect()->route('commission_invoices.show', $invoice->id)
                ->with('error', 'Only Pending invoices can be edited.');
        }

        $products  = Product::with('variations')->orderBy('name')->get();
        $vendors   = ChartOfAccounts::where('account_type', config('commission_accounts.vendor_account_type'))->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', config('commission_accounts.customer_account_type'))->orderBy('name')->get();
        $units     = MeasurementUnit::all();

        return view('commissions.edit', compact('invoice', 'products', 'vendors', 'customers', 'units'));
    }

    // ─────────────────────────────────────────────────────────────
    // UPDATE — only while Pending
    // ─────────────────────────────────────────────────────────────
    public function update(Request $request, $id)
    {
        $request->validate([
            'invoice_date'                    => 'required|date',
            'vendor_id'                       => 'required|exists:chart_of_accounts,id',
            'customer_id'                     => 'required|exists:chart_of_accounts,id',
            'transport_name'                  => 'nullable|string|max:150',
            'bilty_no'                        => 'nullable|string|max:100',
            'vendor_bill_no'                  => 'nullable|string|max:100',
            'ref_no'                          => 'nullable|string|max:100',
            'remarks'                         => 'nullable|string',
            'items'                           => 'required|array|min:1',
            'items.*.product_id'              => 'required|exists:products,id',
            'items.*.variation_id'            => 'nullable|exists:product_variations,id',
            'items.*.unit_id'                 => 'nullable|exists:measurement_units,id',
            'items.*.quantity'                => 'required|numeric|min:0.01',
            'items.*.weight'                  => 'nullable|numeric|min:0',
            'items.*.purchase_price'          => 'required|numeric|min:0',
            'items.*.sale_price'              => 'required|numeric|min:0',
            'items.*.commission_percentage'   => 'required|numeric|min:0|max:100',
            'expenses'                        => 'nullable|array',
            'expenses.*.expense_type'         => 'required_with:expenses|in:packing,local_cartage,misc',
            'expenses.*.description'          => 'nullable|string|max:255',
            'expenses.*.amount'               => 'required_with:expenses|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $invoice = CommissionInvoice::lockForUpdate()->findOrFail($id);

            if (!$invoice->isPending()) {
                DB::rollBack();
                return back()->withErrors(['error' => 'Only Pending invoices can be edited.']);
            }

            $invoice->update([
                'invoice_date'    => $request->invoice_date,
                'vendor_id'       => $request->vendor_id,
                'customer_id'     => $request->customer_id,
                'transport_name'  => $request->transport_name,
                'bilty_no'        => $request->bilty_no,
                'vendor_bill_no'  => $request->vendor_bill_no,
                'ref_no'          => $request->ref_no,
                'remarks'         => $request->remarks,
            ]);

            $totals = $this->syncItemsAndExpenses($invoice, $request->items, $request->expenses ?? []);
            $invoice->update($totals);

            DB::commit();
            return redirect()->route('commission_invoices.index')->with('success', 'Commission Invoice updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] Update error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // MOVE TO IN TRANSIT
    //
    // Requires: Vendor Bill Number, Bilty Number, Transport Name, Attachment.
    // Accounting: DR Commission Goods In Transit / CR Vendor  = Total Purchase Amount
    // ─────────────────────────────────────────────────────────────
    public function moveToInTransit(Request $request, $id)
    {
        $request->validate([
            'vendor_bill_no'  => 'required|string|max:100',
            'bilty_no'        => 'required|string|max:100',
            'transport_name'  => 'required|string|max:150',
            'attachment'      => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'remarks'         => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $invoice = CommissionInvoice::with('attachments')->lockForUpdate()->findOrFail($id);

            if (!$invoice->isPending()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not Pending — no action taken.');
            }

            $hasAttachment = $request->hasFile('attachment')
                || $invoice->attachments()->where('stage', CommissionInvoice::STATUS_PENDING)->exists()
                || $invoice->attachments()->exists();

            if (!$hasAttachment) {
                DB::rollBack();
                return back()->withErrors(['attachment' => 'An attachment (dispatch proof) is required to move to In Transit.']);
            }

            $invoice->update([
                'vendor_bill_no' => $request->vendor_bill_no,
                'bilty_no'       => $request->bilty_no,
                'transport_name' => $request->transport_name,
                'status'         => CommissionInvoice::STATUS_IN_TRANSIT,
            ]);

            if ($request->hasFile('attachment')) {
                $path = $request->file('attachment')->store('commission_invoices', 'public');
                $invoice->attachments()->create([
                    'file_path'     => $path,
                    'original_name' => $request->file('attachment')->getClientOriginalName(),
                    'file_type'     => $request->file('attachment')->getClientMimeType(),
                    'stage'         => CommissionInvoice::STATUS_IN_TRANSIT,
                ]);
            }

            $this->postVoucher(
                now()->toDateString(),
                $this->commissionGoodsInTransitAccount(),
                ChartOfAccounts::findOrFail($invoice->vendor_id),
                $invoice->totalVendorPayable(),
                "CI-{$invoice->id}-INTRANSIT",
                "Commission Invoice #{$invoice->invoice_no} — goods in transit (vendor payable created)"
            );

            $this->logStatusChange($invoice, CommissionInvoice::STATUS_PENDING, CommissionInvoice::STATUS_IN_TRANSIT, $request->remarks);

            DB::commit();
            Log::info('[CI] Moved to In Transit', ['invoice_id' => $invoice->id]);

            return redirect()->route('commission_invoices.show', $invoice->id)
                ->with('success', 'Commission Invoice moved to In Transit. Vendor payable created.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] MoveToInTransit error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // DELIVER  (In Transit -> Delivered)
    //
    // Closes Commission Goods In Transit, creates Customer Receivable,
    // recognizes Commission Income + Expense Reimbursement, and routes
    // any Sale/Purchase/Commission mismatch to the Commission Clearing
    // account (visible, not silently absorbed — see README).
    // ─────────────────────────────────────────────────────────────
    public function deliver(Request $request, $id)
    {
        $request->validate([
            'delivered_at'               => 'required|date',
            'delivery_received_by_name'  => 'nullable|string|max:150',
            'delivery_remarks'           => 'nullable|string',
            'attachment'                 => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
        ]);

        DB::beginTransaction();

        try {
            $invoice = CommissionInvoice::with('attachments')->lockForUpdate()->findOrFail($id);

            if (!$invoice->isInTransit()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not In Transit — no action taken.');
            }

            $hasDeliveryProof = $request->hasFile('attachment')
                || $invoice->attachments()->where('stage', CommissionInvoice::STATUS_DELIVERED)->exists();

            if (!$hasDeliveryProof) {
                DB::rollBack();
                return back()->withErrors(['attachment' => 'Delivery proof (signed receipt/attachment) is required to mark as Delivered.']);
            }

            $invoice->update([
                'status'                     => CommissionInvoice::STATUS_DELIVERED,
                'delivered_at'               => $request->delivered_at,
                'delivered_by'               => auth()->id(),
                'delivery_received_by_name'  => $request->delivery_received_by_name,
                'delivery_remarks'           => $request->delivery_remarks,
            ]);

            if ($request->hasFile('attachment')) {
                $path = $request->file('attachment')->store('commission_invoices', 'public');
                $invoice->attachments()->create([
                    'file_path'     => $path,
                    'original_name' => $request->file('attachment')->getClientOriginalName(),
                    'file_type'     => $request->file('attachment')->getClientMimeType(),
                    'stage'         => CommissionInvoice::STATUS_DELIVERED,
                ]);
            }

            $date              = $request->delivered_at;
            $customerAccount   = ChartOfAccounts::findOrFail($invoice->customer_id);
            $transitAccount    = $this->commissionGoodsInTransitAccount();
            $clearingAccount   = $this->commissionClearingAccount();
            $commissionAccount = $this->commissionIncomeAccount();
            $otherIncomeAcct   = $this->otherIncomeAccount();

            $purchaseTotal   = (float) $invoice->total_purchase_amount;
            $saleTotal       = (float) $invoice->total_sale_amount;
            $commissionTotal = (float) $invoice->total_commission_amount;
            $otherExpenses   = (float) $invoice->total_other_expenses;

            // 1) Close the transit asset exactly as it was booked.
            $this->postVoucher(
                $date, $clearingAccount, $transitAccount, $purchaseTotal,
                "CI-{$invoice->id}-DELIVERED-CLOSE-TRANSIT",
                "Commission Invoice #{$invoice->invoice_no} — close Commission Goods In Transit"
            );

            // 2a) Commission income
            $this->postVoucher(
                $date, $customerAccount, $commissionAccount, $commissionTotal,
                "CI-{$invoice->id}-DELIVERED-COMMISSION",
                "Commission Invoice #{$invoice->invoice_no} — commission income recognized"
            );

            // 2b) Other expense reimbursement
            $this->postVoucher(
                $date, $customerAccount, $otherIncomeAcct, $otherExpenses,
                "CI-{$invoice->id}-DELIVERED-EXPENSES",
                "Commission Invoice #{$invoice->invoice_no} — customer-payable expenses (packing/cartage/misc)"
            );

            // 2c) Residual (goods pass-through) portion — traceable via Clearing account.
            $residual = round($saleTotal - $commissionTotal, 2);
            $this->postVoucher(
                $date, $customerAccount, $clearingAccount, $residual,
                "CI-{$invoice->id}-DELIVERED-RESIDUAL",
                "Commission Invoice #{$invoice->invoice_no} — goods pass-through portion of sale"
            );

            $this->logStatusChange($invoice, CommissionInvoice::STATUS_IN_TRANSIT, CommissionInvoice::STATUS_DELIVERED, $request->delivery_remarks);

            DB::commit();
            Log::info('[CI] Delivered', [
                'invoice_id' => $invoice->id,
                'customer_receivable' => $invoice->totalCustomerReceivable(),
                'commission_income' => $commissionTotal,
            ]);

            return redirect()->route('commission_invoices.show', $invoice->id)
                ->with('success', 'Commission Invoice delivered. Customer receivable and commission income recorded.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] Deliver error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // DESTROY  (soft delete) — only while Pending.
    // ─────────────────────────────────────────────────────────────
    public function destroy($id)
    {
        $invoice = CommissionInvoice::findOrFail($id);

        if (!$invoice->isPending()) {
            return back()->with('error', 'Only Pending invoices can be deleted. In Transit / Delivered invoices require a controlled reversal.');
        }

        DB::beginTransaction();
        try {
            $invoice->items()->delete();
            $invoice->expenses()->delete();
            $invoice->delete();

            DB::commit();
            return redirect()->route('commission_invoices.index')->with('success', 'Invoice deleted.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] Destroy error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Failed to delete invoice.');
        }
    }

    // ─────────────────────────────────────────────────────────────
    // RESTORE
    // ─────────────────────────────────────────────────────────────
    public function restore($id)
    {
        $invoice = CommissionInvoice::onlyTrashed()->findOrFail($id);

        DB::beginTransaction();
        try {
            $invoice->restore();
            DB::commit();
            return back()->with('success', 'Invoice restored.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] Restore error', ['message' => $e->getMessage()]);
            return back()->with('error', 'Restore failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // PRINT (PDF)
    // ─────────────────────────────────────────────────────────────
    public function print($id)
    {
        $invoice = CommissionInvoice::with(['vendor', 'customer', 'items.product', 'items.variation', 'expenses'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('BillTrix');
        $pdf->SetTitle('CI-' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(15, 12);
        $pdf->Cell(180, 10, 'COMMISSION / BROKERAGE INVOICE', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(180, 5, 'Invoice #: ' . $invoice->invoice_no . '   |   Date: ' . Carbon::parse($invoice->invoice_date)->format('d-M-Y') . '   |   Status: ' . $invoice->statusLabel(), 0, 1, 'L');
        $pdf->Ln(3);

        $partiesHtml = '
        <table width="100%" border="1" cellpadding="3" style="font-size:10px;">
            <tr>
                <td width="50%"><b>Vendor:</b> ' . ($invoice->vendor->name ?? 'N/A') . '</td>
                <td width="50%"><b>Customer:</b> ' . ($invoice->customer->name ?? 'N/A') . '</td>
            </tr>
            <tr>
                <td><b>Transport:</b> ' . ($invoice->transport_name ?? '-') . '</td>
                <td><b>Bilty No:</b> ' . ($invoice->bilty_no ?? '-') . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($partiesHtml, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '
        <table border="1" cellpadding="4" style="font-size:9px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="5%">#</th>
                    <th width="20%">Item</th>
                    <th width="8%">Qty</th>
                    <th width="10%">Purchase Price</th>
                    <th width="10%">Sale Price</th>
                    <th width="10%">Comm %</th>
                    <th width="12%">Comm Amt</th>
                    <th width="12%">Purchase Total</th>
                    <th width="13%">Sale Total</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($invoice->items as $index => $item) {
            $html .= '
                <tr>
                    <td width="5%" style="text-align:center;">' . ($index + 1) . '</td>
                    <td width="20%">' . e($item->product->name ?? '-') . '</td>
                    <td width="8%" style="text-align:center;">' . number_format($item->quantity, 2) . '</td>
                    <td width="10%" style="text-align:right;">' . number_format($item->purchase_price, 2) . '</td>
                    <td width="10%" style="text-align:right;">' . number_format($item->sale_price, 2) . '</td>
                    <td width="10%" style="text-align:center;">' . number_format($item->commission_percentage, 2) . '</td>
                    <td width="12%" style="text-align:right;">' . number_format($item->commission_amount, 2) . '</td>
                    <td width="12%" style="text-align:right;">' . number_format($item->purchase_total, 2) . '</td>
                    <td width="13%" style="text-align:right;">' . number_format($item->sale_total, 2) . '</td>
                </tr>';
        }

        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(3);

        if ($invoice->expenses->count()) {
            $expHtml = '<table border="1" cellpadding="4" style="font-size:9px;"><thead><tr style="background-color:#f2f2f2;font-weight:bold;"><th width="30%">Expense</th><th width="50%">Description</th><th width="20%">Amount</th></tr></thead><tbody>';
            foreach ($invoice->expenses as $exp) {
                $expHtml .= '<tr><td width="30%">' . $exp->typeLabel() . '</td><td width="50%">' . e($exp->description) . '</td><td width="20%" style="text-align:right;">' . number_format($exp->amount, 2) . '</td></tr>';
            }
            $expHtml .= '</tbody></table>';
            $pdf->writeHTML($expHtml, true, false, false, false, '');
            $pdf->Ln(3);
        }

        $summaryHtml = '
        <table width="60%" border="1" cellpadding="4" style="font-size:10px;" align="right">
            <tr><td><b>Total Purchase Amount</b></td><td style="text-align:right;">' . number_format($invoice->total_purchase_amount, 2) . '</td></tr>
            <tr><td><b>Total Sale Amount</b></td><td style="text-align:right;">' . number_format($invoice->total_sale_amount, 2) . '</td></tr>
            <tr><td><b>Total Commission</b></td><td style="text-align:right;">' . number_format($invoice->total_commission_amount, 2) . '</td></tr>
            <tr><td><b>Total Other Expenses</b></td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#fafafa;"><td>Total Vendor Payable</td><td style="text-align:right;">' . number_format($invoice->totalVendorPayable(), 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#fafafa;"><td>Total Customer Receivable</td><td style="text-align:right;">' . number_format($invoice->totalCustomerReceivable(), 2) . '</td></tr>
        </table>';
        $pdf->writeHTML($summaryHtml, true, false, false, false, '');

        if ($invoice->remarks) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->remarks, 0, 'L');
        }

        return $pdf->Output('CI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}
