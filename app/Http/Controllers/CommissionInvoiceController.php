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
    private function commissionClearingAccount(): ChartOfAccounts      { return $this->resolveAccount('commission_clearing', 'Commission Clearing'); }

    private function kgPerMaund(): int
    {
        return (int) config('purchase_settings.kg_per_maund', 40);
    }

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
        if (abs($amount) < 0.01) return;

        $dr = $normalDr;
        $cr = $normalCr;

        if ($amount < 0) {
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

    public function index(Request $request)
    {
        $user  = auth()->user();
        $query = CommissionInvoice::with(['vendor', 'customer', 'attachments']);

        if ($request->has('view_deleted')) $query->onlyTrashed();
        if ($request->filled('status')) $query->where('status', $request->status);
        if (!$user->hasRole('superadmin')) $query->where('created_by', $user->id);

        $invoices = $query->latest()->get();

        return view('commissions.index', compact('invoices'));
    }

    public function create()
    {
        $products  = Product::with('variations')->orderBy('name')->get();
        $vendors   = ChartOfAccounts::where('account_type', config('commission_accounts.vendor_account_type'))->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', config('commission_accounts.customer_account_type'))->orderBy('name')->get();
        $units     = MeasurementUnit::all();
        $payeeAccounts = ChartOfAccounts::orderBy('name')->get(); // any account can be an expense payee
        $kgPerMaund = $this->kgPerMaund();

        return view('commissions.create', compact('products', 'vendors', 'customers', 'units', 'payeeAccounts', 'kgPerMaund'));
    }

    /** Shared server-side item/expense computation — never trust client math. */
    private function syncItemsAndExpenses(CommissionInvoice $invoice, array $items, array $expenses): array
    {
        $invoice->items()->delete();
        $invoice->expenses()->delete();

        $kgPerMaund = $this->kgPerMaund();
        $totalQty = $totalWeight = $totalPurchase = $totalSale = 0;
        $totalVendorCommission = $totalCustomerCommission = 0;

        foreach ($items as $itemData) {
            $calc = CommissionInvoiceItem::computeLine($itemData, $kgPerMaund);

            $invoice->items()->create([
                'product_id'                       => $itemData['product_id'],
                'variation_id'                      => $itemData['variation_id'] ?? null,
                'packing_unit_id'                   => $itemData['packing_unit_id'] ?? null,
                'wt_per_packing'                     => (float) $itemData['wt_per_packing'],
                'quantity'                           => (float) $itemData['quantity'],
                'gross_weight'                        => $calc['grossWeight'],
                'net_weight'                          => $calc['netWeight'],
                'purchase_rate_per_40kg'              => (float) $itemData['purchase_rate_per_40kg'],
                'purchase_price'                      => $calc['purchasePriceKg'],
                'purchase_total'                      => $calc['purchaseTotal'],
                'sale_rate_per_40kg'                  => (float) $itemData['sale_rate_per_40kg'],
                'sale_price'                          => $calc['salePriceKg'],
                'sale_total'                          => $calc['saleTotal'],
                'vendor_commission_percentage'        => (float) ($itemData['vendor_commission_percentage'] ?? 0),
                'vendor_commission_amount'            => $calc['vendorCommissionAmount'],
                'customer_commission_percentage'      => (float) ($itemData['customer_commission_percentage'] ?? 0),
                'customer_commission_amount'          => $calc['customerCommissionAmount'],
            ]);

            $totalQty               += (float) $itemData['quantity'];
            $totalWeight            += $calc['netWeight'];
            $totalPurchase          += $calc['purchaseTotal'];
            $totalSale              += $calc['saleTotal'];
            $totalVendorCommission  += $calc['vendorCommissionAmount'];
            $totalCustomerCommission += $calc['customerCommissionAmount'];
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
            'total_quantity'                    => $totalQty,
            'total_weight'                       => round($totalWeight, 3),
            'total_purchase_amount'              => round($totalPurchase, 2),
            'total_sale_amount'                  => round($totalSale, 2),
            'total_vendor_commission_amount'     => round($totalVendorCommission, 2),
            'total_customer_commission_amount'   => round($totalCustomerCommission, 2),
            'total_commission_amount'            => round($totalVendorCommission + $totalCustomerCommission, 2),
            'total_other_expenses'               => round($totalOtherExpenses, 2),
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'invoice_date'                          => 'required|date',
            'vendor_id'                              => 'required|exists:chart_of_accounts,id',
            'customer_id'                             => 'required|exists:chart_of_accounts,id',
            'transport_name'                          => 'nullable|string|max:150',
            'bilty_no'                                 => 'nullable|string|max:100',
            'vendor_bill_no'                            => 'nullable|string|max:100',
            'ref_no'                                    => 'nullable|string|max:100',
            'remarks'                                   => 'nullable|string',
            'items'                                      => 'required|array|min:1',
            'items.*.product_id'                          => 'required|exists:products,id',
            'items.*.variation_id'                         => 'nullable|exists:product_variations,id',
            'items.*.packing_unit_id'                       => 'nullable|exists:measurement_units,id',
            'items.*.wt_per_packing'                         => 'required|numeric|min:0.001',
            'items.*.quantity'                                => 'required|numeric|min:0.01',
            'items.*.net_weight'                               => 'nullable|numeric|min:0',
            'items.*.purchase_rate_per_40kg'                    => 'required|numeric|min:0',
            'items.*.sale_rate_per_40kg'                         => 'required|numeric|min:0',
            'items.*.vendor_commission_percentage'                => 'nullable|numeric|min:0|max:100',
            'items.*.customer_commission_percentage'               => 'nullable|numeric|min:0|max:100',
            'expenses'                                                => 'nullable|array',
            'expenses.*.expense_type'                                  => 'required_with:expenses|in:packing,local_cartage,misc',
            'expenses.*.description'                                    => 'nullable|string|max:255',
            'expenses.*.amount'                                          => 'required_with:expenses|numeric|min:0',
            'expenses.*.paid_by'                                          => 'required_with:expenses|in:vendor,company',
            'expenses.*.payee_account_id'                                  => 'nullable|exists:chart_of_accounts,id',
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
            return redirect()->route('commission_invoices.index')->with('success', 'Commission Invoice created as Pending.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] Store error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        $invoice = CommissionInvoice::with([
            'vendor', 'customer', 'items.product', 'items.variation', 'items.packingUnit',
            'expenses.payeeAccount', 'attachments', 'statusHistories.changedBy',
        ])->findOrFail($id);

        $vouchers = $invoice->vouchers();

        return view('commissions.show', compact('invoice', 'vouchers'));
    }

    public function edit($id)
    {
        $invoice = CommissionInvoice::with(['items', 'expenses'])->findOrFail($id);

        if (!$invoice->isPending()) {
            return redirect()->route('commission_invoices.show', $invoice->id)->with('error', 'Only Pending invoices can be edited.');
        }

        $products  = Product::with('variations')->orderBy('name')->get();
        $vendors   = ChartOfAccounts::where('account_type', config('commission_accounts.vendor_account_type'))->orderBy('name')->get();
        $customers = ChartOfAccounts::where('account_type', config('commission_accounts.customer_account_type'))->orderBy('name')->get();
        $units     = MeasurementUnit::all();
        $payeeAccounts = ChartOfAccounts::orderBy('name')->get();
        $kgPerMaund = $this->kgPerMaund();

        return view('commissions.edit', compact('invoice', 'products', 'vendors', 'customers', 'units', 'payeeAccounts', 'kgPerMaund'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'invoice_date'                          => 'required|date',
            'vendor_id'                              => 'required|exists:chart_of_accounts,id',
            'customer_id'                             => 'required|exists:chart_of_accounts,id',
            'transport_name'                          => 'nullable|string|max:150',
            'bilty_no'                                 => 'nullable|string|max:100',
            'vendor_bill_no'                            => 'nullable|string|max:100',
            'ref_no'                                    => 'nullable|string|max:100',
            'remarks'                                   => 'nullable|string',
            'items'                                      => 'required|array|min:1',
            'items.*.product_id'                          => 'required|exists:products,id',
            'items.*.variation_id'                         => 'nullable|exists:product_variations,id',
            'items.*.packing_unit_id'                       => 'nullable|exists:measurement_units,id',
            'items.*.wt_per_packing'                         => 'required|numeric|min:0.001',
            'items.*.quantity'                                => 'required|numeric|min:0.01',
            'items.*.net_weight'                               => 'nullable|numeric|min:0',
            'items.*.purchase_rate_per_40kg'                    => 'required|numeric|min:0',
            'items.*.sale_rate_per_40kg'                         => 'required|numeric|min:0',
            'items.*.vendor_commission_percentage'                => 'nullable|numeric|min:0|max:100',
            'items.*.customer_commission_percentage'               => 'nullable|numeric|min:0|max:100',
            'expenses'                                                => 'nullable|array',
            'expenses.*.expense_type'                                  => 'required_with:expenses|in:packing,local_cartage,misc',
            'expenses.*.description'                                    => 'nullable|string|max:255',
            'expenses.*.amount'                                          => 'required_with:expenses|numeric|min:0',
            'expenses.*.paid_by'                                          => 'required_with:expenses|in:vendor,company',
            'expenses.*.payee_account_id'                                  => 'nullable|exists:chart_of_accounts,id',
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

            // Vendor Payable created at FULL purchase amount here — vendor
            // commission (if any) is recognized and nets this down at
            // Delivered, keeping the timing symmetric with customer
            // commission and matching how Purchase's In Transit works.
            $this->postVoucher(
                now()->toDateString(),
                $this->commissionGoodsInTransitAccount(),
                ChartOfAccounts::findOrFail($invoice->vendor_id),
                (float) $invoice->total_purchase_amount,
                "CI-{$invoice->id}-INTRANSIT",
                "Commission Invoice #{$invoice->invoice_no} — goods in transit (vendor payable created)"
            );

            $this->logStatusChange($invoice, CommissionInvoice::STATUS_PENDING, CommissionInvoice::STATUS_IN_TRANSIT, $request->remarks);

            DB::commit();
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
    //   1) Close transit:      DR Clearing         / CR Transit           = Purchase Amount
    //   2) Vendor commission:  DR Vendor            / CR Commission Income = Vendor Commission (reduces vendor payable)
    //   3) Customer commission:DR Customer           / CR Commission Income = Customer Commission
    //   4) Per expense:        DR Customer           / CR (Vendor OR Payee) = expense.amount
    //   5) Residual:           DR Customer           / CR Clearing          = Sale Total - Customer Commission
    //
    // Customer Receivable always = Sale Total + sum(all expenses), regardless
    // of who ultimately gets paid for each expense — only the CREDIT side of
    // step 4 changes based on paid_by.
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
            $invoice = CommissionInvoice::with(['attachments', 'expenses'])->lockForUpdate()->findOrFail($id);

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
            $vendorAccount     = ChartOfAccounts::findOrFail($invoice->vendor_id);
            $transitAccount    = $this->commissionGoodsInTransitAccount();
            $clearingAccount   = $this->commissionClearingAccount();
            $commissionAccount = $this->commissionIncomeAccount();

            $purchaseTotal          = (float) $invoice->total_purchase_amount;
            $saleTotal               = (float) $invoice->total_sale_amount;
            $vendorCommissionTotal   = (float) $invoice->total_vendor_commission_amount;
            $customerCommissionTotal = (float) $invoice->total_customer_commission_amount;

            // 1) Close the transit asset exactly as it was booked.
            $this->postVoucher(
                $date, $clearingAccount, $transitAccount, $purchaseTotal,
                "CI-{$invoice->id}-DELIVERED-CLOSE-TRANSIT",
                "Commission Invoice #{$invoice->invoice_no} — close Commission Goods In Transit"
            );

            // 2) Vendor-side commission — reduces what we owe the vendor.
            $this->postVoucher(
                $date, $vendorAccount, $commissionAccount, $vendorCommissionTotal,
                "CI-{$invoice->id}-DELIVERED-VENDOR-COMMISSION",
                "Commission Invoice #{$invoice->invoice_no} — commission from vendor (reduces vendor payable)"
            );

            // 3) Customer-side commission.
            $this->postVoucher(
                $date, $customerAccount, $commissionAccount, $customerCommissionTotal,
                "CI-{$invoice->id}-DELIVERED-CUSTOMER-COMMISSION",
                "Commission Invoice #{$invoice->invoice_no} — commission from customer"
            );

            // 4) Each expense: customer always owes it; the payable target
            // depends on who's actually paying it.
            foreach ($invoice->expenses as $i => $expense) {
                $targetAccount = $expense->paid_by === \App\Models\CommissionInvoiceExpense::PAID_BY_VENDOR
                    ? $vendorAccount
                    : ChartOfAccounts::find($expense->payee_account_id);

                if (!$targetAccount) {
                    throw new \Exception("Expense #{$expense->id} ({$expense->typeLabel()}) has no valid payee account selected.");
                }

                $this->postVoucher(
                    $date, $customerAccount, $targetAccount, (float) $expense->amount,
                    "CI-{$invoice->id}-DELIVERED-EXPENSE-" . ($i + 1),
                    "Commission Invoice #{$invoice->invoice_no} — {$expense->typeLabel()} expense, paid by {$expense->paidByLabel()}"
                );
            }

            // 5) Residual (goods pass-through) — only customer-side commission
            // is netted out here; vendor commission doesn't touch what the
            // customer owes.
            $residual = round($saleTotal - $customerCommissionTotal, 2);
            $this->postVoucher(
                $date, $customerAccount, $clearingAccount, $residual,
                "CI-{$invoice->id}-DELIVERED-RESIDUAL",
                "Commission Invoice #{$invoice->invoice_no} — goods pass-through portion of sale"
            );

            $this->logStatusChange($invoice, CommissionInvoice::STATUS_IN_TRANSIT, CommissionInvoice::STATUS_DELIVERED, $request->delivery_remarks);

            DB::commit();
            return redirect()->route('commission_invoices.show', $invoice->id)
                ->with('success', 'Commission Invoice delivered. Receivables, payables, and commission income recorded.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] Deliver error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        $invoice = CommissionInvoice::findOrFail($id);

        if (!$invoice->isPending()) {
            return back()->with('error', 'Only Pending invoices can be deleted.');
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
        </table>';
        $pdf->writeHTML($partiesHtml, true, false, false, false, '');
        $pdf->Ln(5);

        $html = '
        <table border="1" cellpadding="3" style="font-size:8px;">
            <thead>
                <tr style="background-color:#f2f2f2;font-weight:bold;text-align:center;">
                    <th width="14%">Item</th><th width="6%">Qty</th><th width="8%">Net Wt</th>
                    <th width="9%">Pur Rate/kg</th><th width="9%">Pur Total</th>
                    <th width="9%">Sale Rate/kg</th><th width="9%">Sale Total</th>
                    <th width="9%">Vendor Comm %</th><th width="9%">Vendor Comm</th>
                    <th width="9%">Cust Comm %</th><th width="9%">Cust Comm</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($invoice->items as $item) {
            $html .= '
                <tr>
                    <td width="14%">' . e($item->product->name ?? '-') . '</td>
                    <td width="6%" style="text-align:center;">' . number_format($item->quantity, 0) . '</td>
                    <td width="8%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->purchase_price, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->purchase_total, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->sale_price, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->sale_total, 2) . '</td>
                    <td width="9%" style="text-align:center;">' . number_format($item->vendor_commission_percentage, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->vendor_commission_amount, 2) . '</td>
                    <td width="9%" style="text-align:center;">' . number_format($item->customer_commission_percentage, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->customer_commission_amount, 2) . '</td>
                </tr>';
        }
        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(3);

        if ($invoice->expenses->count()) {
            $expHtml = '<table border="1" cellpadding="4" style="font-size:9px;"><thead><tr style="background-color:#f2f2f2;font-weight:bold;"><th width="25%">Expense</th><th width="35%">Description</th><th width="20%">Paid By</th><th width="20%">Amount</th></tr></thead><tbody>';
            foreach ($invoice->expenses as $exp) {
                $expHtml .= '<tr><td width="25%">' . $exp->typeLabel() . '</td><td width="35%">' . e($exp->description) . '</td><td width="20%">' . $exp->paidByLabel() . '</td><td width="20%" style="text-align:right;">' . number_format($exp->amount, 2) . '</td></tr>';
            }
            $expHtml .= '</tbody></table>';
            $pdf->writeHTML($expHtml, true, false, false, false, '');
            $pdf->Ln(3);
        }

        $summaryHtml = '
        <table width="65%" border="1" cellpadding="4" style="font-size:10px;" align="right">
            <tr><td><b>Total Purchase Amount</b></td><td style="text-align:right;">' . number_format($invoice->total_purchase_amount, 2) . '</td></tr>
            <tr><td><b>Total Sale Amount</b></td><td style="text-align:right;">' . number_format($invoice->total_sale_amount, 2) . '</td></tr>
            <tr><td><b>Vendor Commission</b></td><td style="text-align:right;">' . number_format($invoice->total_vendor_commission_amount, 2) . '</td></tr>
            <tr><td><b>Customer Commission</b></td><td style="text-align:right;">' . number_format($invoice->total_customer_commission_amount, 2) . '</td></tr>
            <tr><td><b>Total Other Expenses</b></td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#fafafa;"><td>Total Vendor Payable</td><td style="text-align:right;">' . number_format($invoice->totalVendorPayable(), 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#fafafa;"><td>Total Customer Receivable</td><td style="text-align:right;">' . number_format($invoice->totalCustomerReceivable(), 2) . '</td></tr>
        </table>';
        $pdf->writeHTML($summaryHtml, true, false, false, false, '');

        return $pdf->Output('CI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}
