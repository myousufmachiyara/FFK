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
        $payeeAccounts = ChartOfAccounts::whereIn('account_type', ['vendor', 'cash', 'bank'])->orderBy('name')->get(); // vendor payable, or a direct cash/bank payment
        $kgPerMaund = $this->kgPerMaund();

        return view('commissions.create', compact('products', 'vendors', 'customers', 'units', 'payeeAccounts', 'kgPerMaund'));
    }

    /** Shared server-side item/expense computation — never trust client math. */
    private function syncItemsAndExpenses(CommissionInvoice $invoice, array $items, array $expenses): array
    {
        $invoice->items()->delete();
        $invoice->expenses()->delete();

        $kgPerMaund = $this->kgPerMaund();
        $totalQty = $totalWeight = $totalGrossWeight = $totalPurchase = $totalSale = 0;
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
            $totalGrossWeight       += $calc['grossWeight'];
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
            'total_gross_weight'                 => round($totalGrossWeight, 3),
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
            'payment_terms'                              => 'required|in:cash,credit',
            'credit_days'                                => 'required_if:payment_terms,credit|nullable|integer|min:1',
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
            'expenses.*.expense_type'                                  => 'required_with:expenses|in:local_cartage,packaging,plastic_bags,bardana,misc,tulai,others',
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
                'payment_terms'   => $request->payment_terms,
                'credit_days'     => $request->payment_terms === 'credit' ? $request->credit_days : null,
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
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get();

        return view('commissions.show', compact('invoice', 'vouchers', 'paymentAccounts'));
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
        $payeeAccounts = ChartOfAccounts::whereIn('account_type', ['vendor', 'cash', 'bank'])->orderBy('name')->get();
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
            'payment_terms'                              => 'required|in:cash,credit',
            'credit_days'                                => 'required_if:payment_terms,credit|nullable|integer|min:1',
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
            'expenses.*.expense_type'                                  => 'required_with:expenses|in:local_cartage,packaging,plastic_bags,bardana,misc,tulai,others',
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
                'payment_terms'   => $request->payment_terms,
                'credit_days'     => $request->payment_terms === 'credit' ? $request->credit_days : null,
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
    // REVERT DISPATCH — In Transit -> Pending. Deletes the INTRANSIT
    // voucher (nothing else was posted at that stage). Use for a
    // mistaken dispatch.
    // ─────────────────────────────────────────────────────────────
    public function revertToPending(Request $request, $id)
    {
        $request->validate(['remarks' => 'nullable|string']);

        DB::beginTransaction();

        try {
            $invoice = CommissionInvoice::lockForUpdate()->findOrFail($id);

            if (!$invoice->isInTransit()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not In Transit — nothing to revert.');
            }

            Voucher::where('reference', "CI-{$invoice->id}-INTRANSIT")->delete();
            $invoice->update(['status' => CommissionInvoice::STATUS_PENDING]);

            $this->logStatusChange(
                $invoice, CommissionInvoice::STATUS_IN_TRANSIT, CommissionInvoice::STATUS_PENDING,
                'Reverted from In Transit (mistaken dispatch). ' . ($request->remarks ?? '')
            );

            DB::commit();
            return redirect()->route('commission_invoices.show', $invoice->id)
                ->with('success', 'Dispatch reverted. Invoice is back to Pending and the vendor payable voucher was removed.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] RevertToPending error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // UNDO DELIVERY — Delivered -> In Transit. Reverses every voucher
    // Deliver() posted (close-transit, vendor commission, customer
    // commission, all per-expense vouchers, and the residual). Use for
    // a mistaken delivery confirmation.
    // ─────────────────────────────────────────────────────────────
    public function revertToInTransit(Request $request, $id)
    {
        $request->validate(['remarks' => 'nullable|string']);

        DB::beginTransaction();

        try {
            $invoice = CommissionInvoice::lockForUpdate()->findOrFail($id);

            if (!$invoice->isDelivered()) {
                DB::rollBack();
                return back()->with('error', 'This invoice is not Delivered — nothing to undo.');
            }

            if ((float) $invoice->amount_paid_to_vendor > 0 || (float) $invoice->amount_received_from_customer > 0) {
                DB::rollBack();
                return back()->with('error', 'A payment or receipt has already been recorded against this invoice — undoing delivery would leave that orphaned. This does not reverse a real bank transaction automatically, so it is blocked. Contact an admin if this genuinely needs correcting.');
            }

            Voucher::where('reference', 'like', "CI-{$invoice->id}-DELIVERED-%")->delete();

            $invoice->update([
                'status'                     => CommissionInvoice::STATUS_IN_TRANSIT,
                'delivered_at'               => null,
                'delivered_by'               => null,
                'delivery_received_by_name'  => null,
                'delivery_remarks'           => null,
            ]);

            $this->logStatusChange(
                $invoice, CommissionInvoice::STATUS_DELIVERED, CommissionInvoice::STATUS_IN_TRANSIT,
                'Reverted from Delivered (mistaken confirmation). ' . ($request->remarks ?? '')
            );

            DB::commit();
            return redirect()->route('commission_invoices.show', $invoice->id)
                ->with('success', 'Delivery reverted. Invoice is back to In Transit, all Delivered vouchers removed.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] RevertToInTransit error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
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
            'vendor_payment_account_id'    => 'nullable|exists:chart_of_accounts,id',
            'amount_paid_to_vendor'        => 'nullable|numeric|min:0',
            'customer_receipt_account_id'  => 'nullable|exists:chart_of_accounts,id',
            'amount_received_from_customer'=> 'nullable|numeric|min:0',
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

            // 6) Optional immediate payment to vendor / receipt from
            // customer — now that both totals are finally known.
            $amountPaidToVendor = (float) ($request->amount_paid_to_vendor ?? 0);
            if ($amountPaidToVendor > 0) {
                if (!$request->vendor_payment_account_id) {
                    throw new \Exception('A payment account is required to record a payment to the vendor.');
                }
                if ($amountPaidToVendor > $invoice->totalVendorPayable()) {
                    throw new \Exception('Amount paid to vendor cannot exceed the total vendor payable.');
                }
                $this->postVoucher(
                    $date, $vendorAccount, ChartOfAccounts::findOrFail($request->vendor_payment_account_id), $amountPaidToVendor,
                    "CI-{$invoice->id}-VENDOR-PAYMENT-1",
                    "Commission Invoice #{$invoice->invoice_no} — payment to vendor"
                );
                $invoice->update(['amount_paid_to_vendor' => $amountPaidToVendor]);
            }

            $amountReceivedFromCustomer = (float) ($request->amount_received_from_customer ?? 0);
            if ($amountReceivedFromCustomer > 0) {
                if (!$request->customer_receipt_account_id) {
                    throw new \Exception('A receipt account is required to record a receipt from the customer.');
                }
                if ($amountReceivedFromCustomer > $invoice->totalCustomerReceivable()) {
                    throw new \Exception('Amount received from customer cannot exceed the total customer receivable.');
                }
                $this->postVoucher(
                    $date, ChartOfAccounts::findOrFail($request->customer_receipt_account_id), $customerAccount, $amountReceivedFromCustomer,
                    "CI-{$invoice->id}-CUSTOMER-RECEIPT-1",
                    "Commission Invoice #{$invoice->invoice_no} — receipt from customer"
                );
                $invoice->update(['amount_received_from_customer' => $amountReceivedFromCustomer]);
            }

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

    // ─────────────────────────────────────────────────────────────
    // ADD VENDOR PAYMENT — records an additional payment to the vendor
    // after Delivery. Only valid once Delivered.
    // ─────────────────────────────────────────────────────────────
    public function addVendorPayment(Request $request, $id)
    {
        $request->validate([
            'payment_date'       => 'required|date',
            'payment_account_id' => 'required|exists:chart_of_accounts,id',
            'amount'             => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $invoice = CommissionInvoice::with('vendor')->lockForUpdate()->findOrFail($id);

            if (!$invoice->isDelivered()) {
                DB::rollBack();
                return back()->with('error', 'Payments can only be recorded once the invoice is Delivered.');
            }

            $remaining = $invoice->vendorRemainingBalance();
            $amount = (float) $request->amount;

            if ($amount > $remaining) {
                DB::rollBack();
                return back()->withErrors(['amount' => "Amount cannot exceed the remaining vendor balance ({$remaining})."]);
            }

            $count = Voucher::where('reference', 'like', "CI-{$invoice->id}-VENDOR-PAYMENT-%")->count();

            $this->postVoucher(
                $request->payment_date, $invoice->vendor, ChartOfAccounts::findOrFail($request->payment_account_id), $amount,
                "CI-{$invoice->id}-VENDOR-PAYMENT-" . ($count + 1),
                "Commission Invoice #{$invoice->invoice_no} — additional payment to vendor"
            );

            $invoice->update(['amount_paid_to_vendor' => (float) $invoice->amount_paid_to_vendor + $amount]);

            DB::commit();
            return redirect()->route('commission_invoices.show', $invoice->id)->with('success', 'Payment recorded.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] AddVendorPayment error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // ADD CUSTOMER RECEIPT — records an additional receipt from the
    // customer after Delivery. Only valid once Delivered.
    // ─────────────────────────────────────────────────────────────
    public function addCustomerReceipt(Request $request, $id)
    {
        $request->validate([
            'receipt_date'       => 'required|date',
            'receipt_account_id' => 'required|exists:chart_of_accounts,id',
            'amount'             => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {
            $invoice = CommissionInvoice::with('customer')->lockForUpdate()->findOrFail($id);

            if (!$invoice->isDelivered()) {
                DB::rollBack();
                return back()->with('error', 'Receipts can only be recorded once the invoice is Delivered.');
            }

            $remaining = $invoice->customerRemainingBalance();
            $amount = (float) $request->amount;

            if ($amount > $remaining) {
                DB::rollBack();
                return back()->withErrors(['amount' => "Amount cannot exceed the remaining customer balance ({$remaining})."]);
            }

            $count = Voucher::where('reference', 'like', "CI-{$invoice->id}-CUSTOMER-RECEIPT-%")->count();

            $this->postVoucher(
                $request->receipt_date, ChartOfAccounts::findOrFail($request->receipt_account_id), $invoice->customer, $amount,
                "CI-{$invoice->id}-CUSTOMER-RECEIPT-" . ($count + 1),
                "Commission Invoice #{$invoice->invoice_no} — additional receipt from customer"
            );

            $invoice->update(['amount_received_from_customer' => (float) $invoice->amount_received_from_customer + $amount]);

            DB::commit();
            return redirect()->route('commission_invoices.show', $invoice->id)->with('success', 'Receipt recorded.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[CI] AddCustomerReceipt error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
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

    /** English number-to-words, standard Million/Thousand system, whole rupees. */
    private function numberToWords(float $number): string
    {
        $number = (int) round($number);
        if ($number == 0) return 'Zero';

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
                 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
                 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $convertHundreds = function ($n) use (&$convertHundreds, $ones, $tens) {
            $str = '';
            if ($n >= 100) {
                $str .= $ones[intdiv($n, 100)] . ' Hundred ';
                $n %= 100;
            }
            if ($n >= 20) {
                $str .= $tens[intdiv($n, 10)] . ' ';
                $n %= 10;
            }
            if ($n > 0) {
                $str .= $ones[$n] . ' ';
            }
            return $str;
        };

        $parts = [];
        $billions = intdiv($number, 1000000000); $number %= 1000000000;
        $millions = intdiv($number, 1000000);    $number %= 1000000;
        $thousands = intdiv($number, 1000);       $number %= 1000;
        $rest = $number;

        if ($billions  > 0) $parts[] = trim($convertHundreds($billions))  . ' Billion';
        if ($millions  > 0) $parts[] = trim($convertHundreds($millions))  . ' Million';
        if ($thousands > 0) $parts[] = trim($convertHundreds($thousands)) . ' Thousand';
        if ($rest      > 0) $parts[] = trim($convertHundreds($rest));

        return trim(implode(' ', $parts));
    }

    public function print($id)
    {
        $invoice = CommissionInvoice::with(['vendor', 'customer', 'items.product', 'items.variation', 'expenses.payeeAccount'])->findOrFail($id);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetAuthor('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Commission Invoice #' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        // ── Header band (navy, full width) ─────────────────────────
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, 0, 210, 32, 'F');

        $logoPath = public_path('assets/img/ff-logo.jpg');
        $nameX = 12;
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 5, 22);
            $nameX = 38;
        }

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 19);
        $pdf->SetXY($nameX, 8);
        $pdf->Cell(120, 8, 'FAROOQ FULARA', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetXY($nameX, 17);
        $pdf->SetTextColor(201, 162, 75);
        $pdf->Cell(120, 6, '(KARACHI)', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(120, 8);
        $pdf->Cell(80, 5, 'Farooq Fulara: 0320-2788117', 0, 1, 'R');
        $pdf->SetX(120);
        $pdf->Cell(80, 5, 'Hamiz Farooq Fulara: 0335-0023574', 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);

        // ── Title bar: gold chevron-style label + invoice info box ──
        $pdf->SetFillColor(201, 162, 75);
        $pdf->Rect(10, 38, 90, 12, 'F');
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, 40.5);
        $pdf->Cell(90, 7, '  COMMISSION INVOICE', 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $paymentTermsLine = ucfirst($invoice->payment_terms ?? 'cash');
        if ($invoice->isCredit() && $invoice->credit_days) {
            $paymentTermsLine .= ' (' . $invoice->credit_days . ' days)';
        }
        $dueDateLine = ($invoice->isCredit() && $invoice->dueDate()) ? $invoice->dueDate()->format('d-M-Y') : '—';

        $infoHtml = '<table width="100%" cellpadding="1" style="font-size:9px;">
            <tr><td width="40%"><b>Invoice No</b></td><td width="5%">:</td><td width="55%">CI-' . $invoice->invoice_no . '</td></tr>
            <tr><td><b>Date</b></td><td>:</td><td>' . Carbon::parse($invoice->invoice_date)->format('d-m-Y') . '</td></tr>
            <tr><td><b>Due Date</b></td><td>:</td><td>' . $dueDateLine . '</td></tr>
            <tr><td><b>Status</b></td><td>:</td><td>' . $invoice->statusLabel() . '</td></tr>
            <tr><td><b>Payment Terms</b></td><td>:</td><td>' . $paymentTermsLine . '</td></tr>

        </table>';
        $pdf->SetXY(105, 38);
        $pdf->writeHTMLCell(95, 12, 105, 38, $infoHtml, 1, 1);

        // ── Two boxed detail sections: Vendor | Customer ────────────
        $boxY = 65;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(10, $boxY);
        $pdf->Cell(90, 7, '  Vendor & Customer Details', 1, 0, 'L', true);
        $pdf->SetXY(105, $boxY);
        $pdf->Cell(95, 7, '  Transport Details', 1, 0, 'L', true);
        $pdf->SetTextColor(0, 0, 0);

        $vendorHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="30%"><b>Vendor</b></td><td width="5%">:</td><td width="65%">' . e($invoice->vendor->name ?? 'N/A') . '</td></tr>
            <tr><td><b>Vendor Bill No</b></td><td width="5%">:</td><td>' . ($invoice->vendor_bill_no ?? '-') . '</td></tr>
            <tr><td><b>Customer</b></td><td width="5%">:</td><td width="65%">' . e($invoice->customer->name ?? 'N/A') . '</td></tr>
        </table>';
        $custHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="30%"><b>Transport</b></td><td width="5%">:</td><td>' . ($invoice->transport_name ?? '-') . '</td></tr>
            <tr><td width="30%"><b>Bilti No</b></td><td width="5%">:</td><td>' . ($invoice->bilty_no ?? '-') . '</td></tr>
        </table>';

        $pdf->SetXY(10, $boxY + 7);
        $pdf->writeHTMLCell(90, 22, 10, $boxY + 7, $vendorHtml, 1, 0);
        $pdf->SetXY(105, $boxY + 7);
        $pdf->writeHTMLCell(95, 22, 105, $boxY + 7, $custHtml, 1, 1);

        $pdf->SetY($boxY + 32);

        // ── Items table ─────────────────────────────────────────────
        $html = '
        <table border="1" cellpadding="2.5" style="font-size:7.5px;">
            <thead>
                <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;text-align:center;">
                    <th width="15%">Item</th><th width="5%">Qty</th><th width="7%">G.Wt</th><th width="7%">N.Wt</th>
                    <th width="8%">P.Rate/kg</th><th width="9%">P.Total</th>
                    <th width="8%">S.Rate/kg</th><th width="9%">S.Total</th>
                    <th width="5%">V.C %</th><th width="9%">V.C</th>
                    <th width="5%">C.C %</th><th width="9%">C.C</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($invoice->items as $index => $item) {
            $rowBg = $index % 2 === 0 ? '#ffffff' : '#F5EFDF';
            $html .= '
                <tr style="background-color:' . $rowBg . ';">
                    <td width="15%">' . e($item->product->name ?? '-') . '</td>
                    <td width="5%" style="text-align:center;">' . number_format($item->quantity, 0) . '</td>
                    <td width="7%" style="text-align:right;">' . number_format($item->gross_weight, 2) . '</td>
                    <td width="7%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>
                    <td width="8%" style="text-align:right;">' . number_format($item->purchase_price, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->purchase_total, 2) . '</td>
                    <td width="8%" style="text-align:right;">' . number_format($item->sale_price, 2) . '</td>
                    <td width="8%" style="text-align:right;">' . number_format($item->sale_total, 2) . '</td>
                    <td width="5%" style="text-align:center;">' . number_format($item->vendor_commission_percentage, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->vendor_commission_amount, 2) . '</td>
                    <td width="5%" style="text-align:center;">' . number_format($item->customer_commission_percentage, 2) . '</td>
                    <td width="9%" style="text-align:right;">' . number_format($item->customer_commission_amount, 2) . '</td>
                </tr>';
        }
        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, false, false, '');
        $pdf->Ln(4);

        // ── Additional Info (expenses) | Totals — side by side ──────
        $sideY = $pdf->GetY();

        $expRows = '';
        foreach ($invoice->expenses as $exp) {
            $payTo = $exp->paid_by === 'vendor' ? ($invoice->vendor->name ?? '-') : ($exp->payeeAccount->name ?? '-');
            $expRows .= '<tr><td width="50%">' . $exp->typeLabel() . '</td><td width="25%">' . $exp->paidByLabel() . '</td><td width="25%" style="text-align:right;">' . number_format($exp->amount, 2) . '</td></tr>';
        }
        if (!$invoice->expenses->count()) {
            $expRows = '<tr><td colspan="3" style="color:#888;">No Other Expenses</td></tr>';
        }

        $leftHtml = '
        <table width="100%" cellpadding="2" style="font-size:8.5px;">
            <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;"><td colspan="3">  Additional Information — Expenses</td></tr>
            ' . $expRows . '
            <tr style="font-weight:bold;background-color:#F5EFDF;"><td colspan="2">Total Expenses</td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
        </table>';

        $rightRows = '
            <tr><td width="55%">Total Purchase Amount</td><td width="45%" style="text-align:right;">' . number_format($invoice->total_purchase_amount, 2) . '</td></tr>
            <tr><td>Total Sale Amount</td><td style="text-align:right;">' . number_format($invoice->total_sale_amount, 2) . '</td></tr>
            <tr><td>Vendor Commission</td><td style="text-align:right;">' . number_format($invoice->total_vendor_commission_amount, 2) . '</td></tr>
            <tr><td>Customer Commission</td><td style="text-align:right;">' . number_format($invoice->total_customer_commission_amount, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;"><td>Vendor Payable</td><td style="text-align:right;">' . number_format($invoice->totalVendorPayable(), 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;"><td>Customer Receivable</td><td style="text-align:right;">' . number_format($invoice->totalCustomerReceivable(), 2) . '</td></tr>';

        if ($invoice->isDelivered()) {
            $rightRows .= '
            <tr><td>Paid to Vendor</td><td style="text-align:right;">' . number_format($invoice->amount_paid_to_vendor, 2) . '</td></tr>
            <tr style="color:#b30000;"><td>Vendor Balance Remaining</td><td style="text-align:right;">' . number_format($invoice->vendorRemainingBalance(), 2) . '</td></tr>
            <tr><td>Received from Customer</td><td style="text-align:right;">' . number_format($invoice->amount_received_from_customer, 2) . '</td></tr>
            <tr style="color:#b30000;"><td>Customer Balance Remaining</td><td style="text-align:right;">' . number_format($invoice->customerRemainingBalance(), 2) . '</td></tr>';
        }

        $rightHtml = '<table width="100%" cellpadding="2" style="font-size:8.5px;">' . $rightRows . '</table>';

        $pdf->SetXY(10, $sideY);
        $pdf->writeHTMLCell(90, 0, 10, $sideY, $leftHtml, 1, 0);
        $leftEndY = $pdf->GetY();

        $pdf->SetXY(105, $sideY);
        $pdf->writeHTMLCell(95, 0, 105, $sideY, $rightHtml, 1, 1);
        $rightEndY = $pdf->GetY();

        $pdf->SetY(max($leftEndY, $rightEndY) + 4);

        // ── Amount in Words (Customer Receivable — the figure the
        // customer actually needs to know they owe) ──────────────────
        $wordsHtml = '<table width="100%" cellpadding="3" style="font-size:9px;border:1px solid #1B3A5C;">
            <tr style="background-color:#F5EFDF;">
                <td><b>Customer Receivable in Words:</b> ' . $this->numberToWords($invoice->totalCustomerReceivable()) . ' Only.</td>
            </tr>
        </table>';
        $pdf->writeHTML($wordsHtml, true, false, false, false, '');
        $pdf->Ln(2);

        if ($invoice->delivery_remarks) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->delivery_remarks, 0, 'L');
        }

        // ── Signature ───────────────────────────────────────────────
        $pdf->SetFont('helvetica', '', 10);
        $ySign = $pdf->GetY() + 20;
        if ($ySign > 250) { $pdf->AddPage(); $ySign = 30; }

        $pdf->Line(140, $ySign, 195, $ySign);
        $pdf->SetXY(140, $ySign + 1);
        $pdf->Cell(55, 5, 'Authorized Signature', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(140);
        $pdf->Cell(55, 5, 'FAROOQ FULARA (KARACHI)', 0, 0, 'C');

        // ── Footer band ───────────────────────────────────────────────
        $footY = 282;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, $footY, 210, 15, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(10, $footY + 4);
        $pdf->Cell(190, 5, 'Farooq Fulara: 0320-2788117   |   Hamiz Farooq Fulara: 0335-0023574   |   Karachi, Pakistan', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);

        return $pdf->Output('CI_' . $invoice->invoice_no . '.pdf', 'I');
    }

    /**
     * Shared items-table builder for the 4 party-specific print variants
     * below. $side is 'customer' or 'vendor' — determines which rate/
     * commission columns appear and which grand total is footed. $show40
     * adds the Rate (40kg) column alongside Rate (kg) when true.
     * The "Detailed" print() above is untouched and does not use this —
     * it always shows everything, exactly as it did before.
     */
    private function commissionPartyItemsHtml($invoice, string $side, bool $show40): array
    {
        if ($side === 'customer') {
            $rateKgCol   = 'sale_price';
            $rate40Col   = 'sale_rate_per_40kg';
            $totalCol    = 'sale_total';
            $commPctCol  = 'customer_commission_percentage';
            $commAmtCol  = 'customer_commission_amount';
            $grandTotal  = (float) $invoice->total_sale_amount;
        } else {
            $rateKgCol   = 'purchase_price';
            $rate40Col   = 'purchase_rate_per_40kg';
            $totalCol    = 'purchase_total';
            $commPctCol  = 'vendor_commission_percentage';
            $commAmtCol  = 'vendor_commission_amount';
            $grandTotal  = (float) $invoice->total_purchase_amount;
        }

        $descW = $show40 ? 24 : 28;
        $qtyW  = 8;
        $wtW   = 11;
        $rate40W = $show40 ? 11 : 0;
        $rateKgW = $show40 ? 11 : 13;
        $totalW  = $show40 ? 14 : 16;
        $commPctW = $show40 ? 10 : 11;
        $commAmtW = 100 - $descW - $qtyW - ($wtW * 2) - $rate40W - $rateKgW - $totalW - $commPctW;

        $html = '
        <table border="1" cellpadding="2.5" style="font-size:8px;">
            <thead>
                <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;text-align:center;">
                    <th width="' . $descW . '%">Description</th>
                    <th width="' . $qtyW . '%">Qty</th>
                    <th width="' . $wtW . '%">Gross Wt</th>
                    <th width="' . $wtW . '%">Net Wt</th>';
        if ($show40) {
            $html .= '<th width="' . $rate40W . '%">Rate (40kg)</th>';
        }
        $html .= '
                    <th width="' . $rateKgW . '%">Rate (kg)</th>
                    <th width="' . $totalW . '%">Total</th>
                    <th width="' . $commPctW . '%">Comm %</th>
                    <th width="' . $commAmtW . '%">Commission</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($invoice->items as $index => $item) {
            $rowBg = $index % 2 === 0 ? '#ffffff' : '#F5EFDF';
            $html .= '
                <tr style="background-color:' . $rowBg . ';">
                    <td width="' . $descW . '%">' . e($item->product->name ?? '-')
                        . ($item->variation->sku ?? null ? ' (' . e($item->variation->sku) . ')' : '') . '</td>
                    <td width="' . $qtyW . '%" style="text-align:center;">' . number_format($item->quantity, 0) . '</td>
                    <td width="' . $wtW . '%" style="text-align:right;">' . number_format($item->gross_weight, 2) . '</td>
                    <td width="' . $wtW . '%" style="text-align:right;">' . number_format($item->net_weight, 2) . '</td>';
            if ($show40) {
                $html .= '<td width="' . $rate40W . '%" style="text-align:right;">' . number_format($item->$rate40Col, 2) . '</td>';
            }
            $html .= '
                    <td width="' . $rateKgW . '%" style="text-align:right;">' . number_format($item->$rateKgCol, 2) . '</td>
                    <td width="' . $totalW . '%" style="text-align:right;">' . number_format($item->$totalCol, 2) . '</td>
                    <td width="' . $commPctW . '%" style="text-align:center;">' . number_format($item->$commPctCol, 2) . '</td>
                    <td width="' . $commAmtW . '%" style="text-align:right;">' . number_format($item->$commAmtCol, 2) . '</td>
                </tr>';
        }

        $footColspan = $show40 ? 6 : 5;
        $html .= '
                <tr style="font-weight:bold;background-color:#F5EFDF;">
                    <td colspan="' . $footColspan . '" style="text-align:right;">Total ' . ($side === 'customer' ? 'Sale' : 'Purchase') . ' Amount</td>
                    <td style="text-align:right;">' . number_format($grandTotal, 2) . '</td>
                    <td></td>
                    <td style="text-align:right;">' . number_format($side === 'customer' ? $invoice->total_customer_commission_amount : $invoice->total_vendor_commission_amount, 2) . '</td>
                </tr>
            </tbody></table>';

        return ['html' => $html];
    }

    /** Shared header + title bar, identical styling to the Detailed print above. */
    private function commissionPartyHeaderAndTitle($pdf, $invoice): void
    {
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, 0, 210, 32, 'F');

        $logoPath = public_path('assets/img/ff-logo.jpg');
        $nameX = 12;
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 5, 22);
            $nameX = 38;
        }

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 19);
        $pdf->SetXY($nameX, 8);
        $pdf->Cell(120, 8, 'FAROOQ FULARA', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetXY($nameX, 17);
        $pdf->SetTextColor(201, 162, 75);
        $pdf->Cell(120, 6, '(KARACHI)', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(120, 8);
        $pdf->Cell(80, 5, 'Farooq Fulara: 0320-2788117', 0, 1, 'R');
        $pdf->SetX(120);
        $pdf->Cell(80, 5, 'Hamiz Farooq Fulara: 0335-0023574', 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetFillColor(201, 162, 75);
        $pdf->Rect(10, 38, 90, 12, 'F');
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, 40.5);
        $pdf->Cell(90, 7, '  COMMISSION INVOICE', 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $paymentTermsLine = ucfirst($invoice->payment_terms ?? 'cash');
        if ($invoice->isCredit() && $invoice->credit_days) {
            $paymentTermsLine .= ' (' . $invoice->credit_days . ' days)';
        }
        $dueDateLine = ($invoice->isCredit() && $invoice->dueDate()) ? $invoice->dueDate()->format('d-M-Y') : '—';

        $infoHtml = '<table width="100%" cellpadding="1" style="font-size:9px;">
            <tr><td width="40%"><b>Invoice No</b></td><td width="5%">:</td><td width="55%">CI-' . $invoice->invoice_no . '</td></tr>
            <tr><td><b>Date</b></td><td>:</td><td>' . Carbon::parse($invoice->invoice_date)->format('d-m-Y') . '</td></tr>
            <tr><td><b>Due Date</b></td><td>:</td><td>' . $dueDateLine . '</td></tr>
            <tr><td><b>Status</b></td><td>:</td><td>' . $invoice->statusLabel() . '</td></tr>
        </table>';
        $pdf->SetXY(105, 38);
        $pdf->writeHTMLCell(95, 12, 105, 38, $infoHtml, 1, 1);
    }

    // ═════════════════════════════════════════════════════════════
    // CUSTOMER-FACING PRINTS — Vendor identity fully removed. Bilty No
    // moves into the Shipment box since the Vendor box (its original
    // home) no longer exists on this copy.
    // ═════════════════════════════════════════════════════════════
    private function printForCustomer($id, bool $show40)
    {
        $invoice = CommissionInvoice::with(['vendor', 'customer', 'items.product', 'items.variation', 'expenses.payeeAccount'])->findOrFail($id);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetAuthor('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Commission Invoice #' . $invoice->invoice_no . ' (Customer Copy)');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $this->commissionPartyHeaderAndTitle($pdf, $invoice);

        $boxY = 60;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(10, $boxY);
        $pdf->Cell(90, 7, '  Customer Details', 1, 0, 'L', true);
        $pdf->SetXY(105, $boxY);
        $pdf->Cell(95, 7, '  Shipment Details', 1, 0, 'L', true);
        $pdf->SetTextColor(0, 0, 0);

        $custHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="30%"><b>Customer</b></td><td width="5%">:</td><td width="65%">' . e($invoice->customer->name ?? 'N/A') . '</td></tr>
        </table>';
        $shipHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="30%"><b>Bilty No</b></td><td width="5%">:</td><td width="65%">' . ($invoice->bilty_no ?? '-') . '</td></tr>
            <tr><td><b>Transport</b></td><td>:</td><td>' . ($invoice->transport_name ?? '-') . '</td></tr>
        </table>';

        $pdf->SetXY(10, $boxY + 7);
        $pdf->writeHTMLCell(90, 16, 10, $boxY + 7, $custHtml, 1, 0);
        $pdf->SetXY(105, $boxY + 7);
        $pdf->writeHTMLCell(95, 16, 105, $boxY + 7, $shipHtml, 1, 1);

        $pdf->SetY($boxY + 26);

        $items = $this->commissionPartyItemsHtml($invoice, 'customer', $show40);
        $pdf->writeHTML($items['html'], true, false, false, false, '');
        $pdf->Ln(4);

        $sideY = $pdf->GetY();

        $expRows = '';
        foreach ($invoice->expenses as $exp) {
            $expRows .= '<tr><td width="65%">' . $exp->typeLabel() . '</td><td width="35%" style="text-align:right;">' . number_format($exp->amount, 2) . '</td></tr>';
        }
        if (!$invoice->expenses->count()) {
            $expRows = '<tr><td colspan="2" style="color:#888;">No Other Expenses</td></tr>';
        }

        $leftHtml = '
        <table width="100%" cellpadding="2" style="font-size:8.5px;">
            <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;"><td colspan="2">  Additional Information — Expenses</td></tr>
            ' . $expRows . '
            <tr style="font-weight:bold;background-color:#F5EFDF;"><td>Total Expenses</td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
        </table>';

        $rightRows = '
            <tr><td width="55%">Total Sale Amount</td><td width="45%" style="text-align:right;">' . number_format($invoice->total_sale_amount, 2) . '</td></tr>
            <tr><td>Customer Commission</td><td style="text-align:right;">' . number_format($invoice->total_customer_commission_amount, 2) . '</td></tr>
            <tr><td>Total Expenses</td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;"><td>Customer Receivable</td><td style="text-align:right;">' . number_format($invoice->totalCustomerReceivable(), 2) . '</td></tr>';

        if ($invoice->isDelivered()) {
            $rightRows .= '
            <tr><td>Received from Customer</td><td style="text-align:right;">' . number_format($invoice->amount_received_from_customer, 2) . '</td></tr>
            <tr style="color:#b30000;"><td>Customer Balance Remaining</td><td style="text-align:right;">' . number_format($invoice->customerRemainingBalance(), 2) . '</td></tr>';
        }

        $rightHtml = '<table width="100%" cellpadding="2" style="font-size:8.5px;">' . $rightRows . '</table>';

        $pdf->SetXY(10, $sideY);
        $pdf->writeHTMLCell(90, 0, 10, $sideY, $leftHtml, 1, 0);
        $leftEndY = $pdf->GetY();
        $pdf->SetXY(105, $sideY);
        $pdf->writeHTMLCell(95, 0, 105, $sideY, $rightHtml, 1, 1);
        $rightEndY = $pdf->GetY();
        $pdf->SetY(max($leftEndY, $rightEndY) + 4);

        $wordsHtml = '<table width="100%" cellpadding="3" style="font-size:9px;border:1px solid #1B3A5C;">
            <tr style="background-color:#F5EFDF;">
                <td><b>Customer Receivable in Words:</b> ' . $this->numberToWords($invoice->totalCustomerReceivable()) . ' Only.</td>
            </tr>
        </table>';
        $pdf->writeHTML($wordsHtml, true, false, false, false, '');
        $pdf->Ln(2);

        if ($invoice->delivery_remarks) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->delivery_remarks, 0, 'L');
        }

        $pdf->SetFont('helvetica', '', 10);
        $ySign = $pdf->GetY() + 20;
        if ($ySign > 250) { $pdf->AddPage(); $ySign = 30; }
        $pdf->Line(140, $ySign, 195, $ySign);
        $pdf->SetXY(140, $ySign + 1);
        $pdf->Cell(55, 5, 'Authorized Signature', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(140);
        $pdf->Cell(55, 5, 'FAROOQ FULARA (KARACHI)', 0, 0, 'C');

        $footY = 282;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, $footY, 210, 15, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(10, $footY + 4);
        $pdf->Cell(190, 5, 'Farooq Fulara: 0320-2788117   |   Hamiz Farooq Fulara: 0335-0023574   |   Karachi, Pakistan', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);

        $suffix = $show40 ? '_customer_both' : '_customer_kg';
        return $pdf->Output('CI_' . $invoice->invoice_no . $suffix . '.pdf', 'I');
    }

    public function printCustomerKgOnly($id)  { return $this->printForCustomer($id, false); }
    public function printCustomerBoth($id)    { return $this->printForCustomer($id, true); }

    // ═════════════════════════════════════════════════════════════
    // VENDOR-FACING PRINTS — Customer identity fully removed. Payment
    // Terms moves into the Shipment box since the Customer box (its
    // original home) no longer exists on this copy.
    // ═════════════════════════════════════════════════════════════
    private function printForVendor($id, bool $show40)
    {
        $invoice = CommissionInvoice::with(['vendor', 'customer', 'items.product', 'items.variation', 'expenses.payeeAccount'])->findOrFail($id);

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetAuthor('Farooq Fulara (Karachi)');
        $pdf->SetTitle('Commission Invoice #' . $invoice->invoice_no . ' (Vendor Copy)');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $this->commissionPartyHeaderAndTitle($pdf, $invoice);

        $paymentTermsLine = ucfirst($invoice->payment_terms ?? 'cash');
        if ($invoice->isCredit() && $invoice->credit_days) {
            $paymentTermsLine .= ' (' . $invoice->credit_days . ' days)';
        }

        $boxY = 60;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY(10, $boxY);
        $pdf->Cell(90, 7, '  Vendor Details', 1, 0, 'L', true);
        $pdf->SetXY(105, $boxY);
        $pdf->Cell(95, 7, '  Shipment Details', 1, 0, 'L', true);
        $pdf->SetTextColor(0, 0, 0);

        $vendorHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="30%"><b>Vendor</b></td><td width="5%">:</td><td width="65%">' . e($invoice->vendor->name ?? 'N/A') . '</td></tr>
            <tr><td><b>Vendor Bill No</b></td><td>:</td><td>' . ($invoice->vendor_bill_no ?? '-') . '</td></tr>
            <tr><td><b>Bilty No</b></td><td>:</td><td>' . ($invoice->bilty_no ?? '-') . '</td></tr>
        </table>';
        $shipHtml = '<table width="100%" cellpadding="2" style="font-size:9px;">
            <tr><td width="30%"><b>Transport</b></td><td width="5%">:</td><td width="65%">' . ($invoice->transport_name ?? '-') . '</td></tr>
            <tr><td><b>Payment Terms</b></td><td>:</td><td>' . $paymentTermsLine . '</td></tr>
        </table>';

        $pdf->SetXY(10, $boxY + 7);
        $pdf->writeHTMLCell(90, 22, 10, $boxY + 7, $vendorHtml, 1, 0);
        $pdf->SetXY(105, $boxY + 7);
        $pdf->writeHTMLCell(95, 22, 105, $boxY + 7, $shipHtml, 1, 1);

        $pdf->SetY($boxY + 32);

        $items = $this->commissionPartyItemsHtml($invoice, 'vendor', $show40);
        $pdf->writeHTML($items['html'], true, false, false, false, '');
        $pdf->Ln(4);

        $sideY = $pdf->GetY();

        $expRows = '';
        foreach ($invoice->expenses as $exp) {
            $expRows .= '<tr><td width="65%">' . $exp->typeLabel() . '</td><td width="35%" style="text-align:right;">' . number_format($exp->amount, 2) . '</td></tr>';
        }
        if (!$invoice->expenses->count()) {
            $expRows = '<tr><td colspan="2" style="color:#888;">No Other Expenses</td></tr>';
        }

        $leftHtml = '
        <table width="100%" cellpadding="2" style="font-size:8.5px;">
            <tr style="background-color:#1B3A5C;color:#ffffff;font-weight:bold;"><td colspan="2">  Additional Information — Expenses</td></tr>
            ' . $expRows . '
            <tr style="font-weight:bold;background-color:#F5EFDF;"><td>Total Expenses</td><td style="text-align:right;">' . number_format($invoice->total_other_expenses, 2) . '</td></tr>
        </table>';

        $rightRows = '
            <tr><td width="55%">Total Purchase Amount</td><td width="45%" style="text-align:right;">' . number_format($invoice->total_purchase_amount, 2) . '</td></tr>
            <tr><td>Vendor Commission</td><td style="text-align:right;">' . number_format($invoice->total_vendor_commission_amount, 2) . '</td></tr>
            <tr style="font-weight:bold;background-color:#C9A24B;color:#ffffff;"><td>Vendor Payable</td><td style="text-align:right;">' . number_format($invoice->totalVendorPayable(), 2) . '</td></tr>';

        if ($invoice->isDelivered()) {
            $rightRows .= '
            <tr><td>Paid to Vendor</td><td style="text-align:right;">' . number_format($invoice->amount_paid_to_vendor, 2) . '</td></tr>
            <tr style="color:#b30000;"><td>Vendor Balance Remaining</td><td style="text-align:right;">' . number_format($invoice->vendorRemainingBalance(), 2) . '</td></tr>';
        }

        $rightHtml = '<table width="100%" cellpadding="2" style="font-size:8.5px;">' . $rightRows . '</table>';

        $pdf->SetXY(10, $sideY);
        $pdf->writeHTMLCell(90, 0, 10, $sideY, $leftHtml, 1, 0);
        $leftEndY = $pdf->GetY();
        $pdf->SetXY(105, $sideY);
        $pdf->writeHTMLCell(95, 0, 105, $sideY, $rightHtml, 1, 1);
        $rightEndY = $pdf->GetY();
        $pdf->SetY(max($leftEndY, $rightEndY) + 4);

        $wordsHtml = '<table width="100%" cellpadding="3" style="font-size:9px;border:1px solid #1B3A5C;">
            <tr style="background-color:#F5EFDF;">
                <td><b>Vendor Payable in Words:</b> ' . $this->numberToWords($invoice->totalVendorPayable()) . ' Only.</td>
            </tr>
        </table>';
        $pdf->writeHTML($wordsHtml, true, false, false, false, '');
        $pdf->Ln(2);

        if ($invoice->delivery_remarks) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->delivery_remarks, 0, 'L');
        }

        $pdf->SetFont('helvetica', '', 10);
        $ySign = $pdf->GetY() + 20;
        if ($ySign > 250) { $pdf->AddPage(); $ySign = 30; }
        $pdf->Line(140, $ySign, 195, $ySign);
        $pdf->SetXY(140, $ySign + 1);
        $pdf->Cell(55, 5, 'Authorized Signature', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(140);
        $pdf->Cell(55, 5, 'FAROOQ FULARA (KARACHI)', 0, 0, 'C');

        $footY = 282;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, $footY, 210, 15, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(10, $footY + 4);
        $pdf->Cell(190, 5, 'Farooq Fulara: 0320-2788117   |   Hamiz Farooq Fulara: 0335-0023574   |   Karachi, Pakistan', 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);

        $suffix = $show40 ? '_vendor_both' : '_vendor_kg';
        return $pdf->Output('CI_' . $invoice->invoice_no . $suffix . '.pdf', 'I');
    }

    public function printVendorKgOnly($id)  { return $this->printForVendor($id, false); }
    public function printVendorBoth($id)    { return $this->printForVendor($id, true); }

}