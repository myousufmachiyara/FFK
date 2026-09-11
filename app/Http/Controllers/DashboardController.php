<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\CommissionInvoice;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use App\Models\Product;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $today      = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd   = Carbon::now()->endOfMonth();

        return view('home', array_merge(
            $this->financialSnapshot($today, $monthStart, $monthEnd),
            $this->salesSnapshot($today, $monthStart, $monthEnd),
            $this->purchasePipeline($monthStart, $monthEnd),
            $this->commissionPipeline($monthStart, $monthEnd),
            $this->inventorySnapshot(),
            $this->recentActivity(),
            $this->overdueInvoices()
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // FINANCIAL SNAPSHOT
    // ─────────────────────────────────────────────────────────────
    private function financialSnapshot($today, $monthStart, $monthEnd): array
    {
        $cashBankIds = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->pluck('id');
        $customerIds = ChartOfAccounts::where('account_type', 'customer')->pluck('id');
        $vendorIds   = ChartOfAccounts::where('account_type', 'vendor')->pluck('id');

        $cashBankBalance = $this->netBalance($cashBankIds, true);
        $totalReceivables = $this->netBalance($customerIds, true);
        $totalPayables    = $this->netBalance($vendorIds, false);

        // FIX: the Commission rebuild split this into two separate
        // voucher references (vendor-side and customer-side commission)
        // — the old single 'CI-%-DELIVERED-COMMISSION' pattern no longer
        // matches anything, so this KPI was silently always showing 0.
        $commissionIncomeMonth = Voucher::where(function ($q) {
                $q->where('reference', 'like', 'CI-%-DELIVERED-VENDOR-COMMISSION')
                  ->orWhere('reference', 'like', 'CI-%-DELIVERED-CUSTOMER-COMMISSION');
            })
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->sum('amount');

        return [
            'cashBankBalance'        => $cashBankBalance,
            'totalReceivables'       => $totalReceivables,
            'totalPayables'          => $totalPayables,
            'commissionIncomeMonth'  => $commissionIncomeMonth,
        ];
    }

    /**
     * Net balance across a set of account ids, using each account's COA
     * opening balance (receivables/payables columns) plus every voucher
     * ever posted against it (cumulative, as-of-today).
     */
    private function netBalance($accountIds, bool $debitNature): float
    {
        if ($accountIds->isEmpty()) return 0.0;

        $openingDr = (float) ChartOfAccounts::whereIn('id', $accountIds)->sum('receivables');
        $openingCr = (float) ChartOfAccounts::whereIn('id', $accountIds)->sum('payables');

        $vDr = (float) Voucher::whereIn('ac_dr_sid', $accountIds)->whereNull('deleted_at')->sum('amount');
        $vCr = (float) Voucher::whereIn('ac_cr_sid', $accountIds)->whereNull('deleted_at')->sum('amount');

        $debit  = $openingDr + $vDr;
        $credit = $openingCr + $vCr;

        return round($debitNature ? ($debit - $credit) : ($credit - $debit), 2);
    }

    // ─────────────────────────────────────────────────────────────
    // SALES SNAPSHOT
    //
    // FIX: Outstanding Receivables was comparing net_amount (items only)
    // against amount_received directly in SQL — since Sale now has an
    // Other Expenses total that's also part of what the customer owes,
    // this understated every invoice with expenses attached. Pulled into
    // PHP so totalBillAmount() (items + expenses) can be used instead.
    //
    // FIX: monthCogs multiplied unit_cost by 'quantity' — quantity is
    // now packing-unit count, not kg, since the weight-based rebuild.
    // Now multiplies by net_weight, matching how COGS is actually
    // posted at Sale time.
    // ─────────────────────────────────────────────────────────────
    private function salesSnapshot($today, $monthStart, $monthEnd): array
    {
        $todaySales = (float) SaleInvoice::whereDate('date', $today)->sum('net_amount');
        $monthSales = (float) SaleInvoice::whereBetween('date', [$monthStart, $monthEnd])->sum('net_amount');

        $cashSalesMonth   = (float) SaleInvoice::where('type', 'cash')
            ->whereBetween('date', [$monthStart, $monthEnd])->sum('net_amount');
        $creditSalesMonth = (float) SaleInvoice::where('type', 'credit')
            ->whereBetween('date', [$monthStart, $monthEnd])->sum('net_amount');

        $outstandingReceivables = SaleInvoice::all()
            ->sum(fn ($s) => max(0, $s->totalBillAmount() - (float) $s->amount_received));

        $monthCogs = (float) DB::table('sale_invoice_items as sii')
            ->join('sale_invoices as si', 'sii.sale_invoice_id', '=', 'si.id')
            ->whereBetween('si.date', [$monthStart, $monthEnd])
            ->sum(DB::raw('sii.unit_cost * sii.net_weight'));

        return [
            'todaySales'             => $todaySales,
            'monthSales'             => $monthSales,
            'cashSalesMonth'         => $cashSalesMonth,
            'creditSalesMonth'       => $creditSalesMonth,
            'outstandingReceivables' => round($outstandingReceivables, 2),
            'monthCogs'              => $monthCogs,
            'monthGrossProfit'       => round($monthSales - $monthCogs, 2),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // PURCHASE PIPELINE
    //
    // FIX: this previously queried 'short_quantity' via whereHas, a
    // column that no longer exists on purchase_invoice_items (renamed
    // to 'short_weight' during the weight-based rebuild) — this would
    // throw a SQL error the moment this dashboard loaded. Also replaced
    // the legacy flat bilty_charges/labor_charges/other_charges columns
    // (no longer written to) with total_other_expenses.
    // ─────────────────────────────────────────────────────────────
    private function purchasePipeline($monthStart, $monthEnd): array
    {
        $pendingCount = PurchaseInvoice::where('status', PurchaseInvoice::STATUS_PENDING)->count();
        $pendingValue = (float) PurchaseInvoice::where('status', PurchaseInvoice::STATUS_PENDING)->sum('total_amount');

        $inTransitCount = PurchaseInvoice::where('status', PurchaseInvoice::STATUS_IN_TRANSIT)->count();
        $inTransitValue = (float) PurchaseInvoice::where('status', PurchaseInvoice::STATUS_IN_TRANSIT)->sum('total_amount');

        $receivedThisMonth = PurchaseInvoice::where('status', PurchaseInvoice::STATUS_RECEIVED)
            ->whereBetween('received_at', [$monthStart, $monthEnd])->get();

        $receivedThisMonthCount = $receivedThisMonth->count();
        $receivedThisMonthValue = round(
            $receivedThisMonth->sum('total_amount') + $receivedThisMonth->sum('total_other_expenses'),
            2
        );

        $shortageInvoicesThisMonth = PurchaseInvoice::where('status', PurchaseInvoice::STATUS_RECEIVED)
            ->whereBetween('received_at', [$monthStart, $monthEnd])
            ->whereHas('items', fn ($q) => $q->where('short_weight', '>', 0))
            ->count();

        return [
            'purchasePendingCount'       => $pendingCount,
            'purchasePendingValue'       => $pendingValue,
            'purchaseInTransitCount'     => $inTransitCount,
            'purchaseInTransitValue'     => $inTransitValue,
            'purchaseReceivedMonthCount' => $receivedThisMonthCount,
            'purchaseReceivedMonthValue' => $receivedThisMonthValue,
            'purchaseShortageCountMonth' => $shortageInvoicesThisMonth,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // COMMISSION PIPELINE
    //
    // Added the vendor/customer commission split alongside the existing
    // combined total, since that distinction is now the meaningful one
    // (vendor payable is reduced by vendor commission specifically).
    // ─────────────────────────────────────────────────────────────
    private function commissionPipeline($monthStart, $monthEnd): array
    {
        $pendingCount = CommissionInvoice::where('status', CommissionInvoice::STATUS_PENDING)->count();

        $inTransitCount = CommissionInvoice::where('status', CommissionInvoice::STATUS_IN_TRANSIT)->count();
        $inTransitValue = (float) CommissionInvoice::where('status', CommissionInvoice::STATUS_IN_TRANSIT)->sum('total_purchase_amount');

        $deliveredThisMonth = CommissionInvoice::where('status', CommissionInvoice::STATUS_DELIVERED)
            ->whereBetween('delivered_at', [$monthStart, $monthEnd])->get();

        $deliveredThisMonthCount      = $deliveredThisMonth->count();
        $deliveredThisMonthSaleValue  = (float) $deliveredThisMonth->sum('total_sale_amount');
        $deliveredThisMonthCommission = (float) $deliveredThisMonth->sum('total_commission_amount');
        $deliveredVendorCommission    = (float) $deliveredThisMonth->sum('total_vendor_commission_amount');
        $deliveredCustomerCommission  = (float) $deliveredThisMonth->sum('total_customer_commission_amount');

        return [
            'commissionPendingCount'          => $pendingCount,
            'commissionInTransitCount'        => $inTransitCount,
            'commissionInTransitValue'        => $inTransitValue,
            'commissionDeliveredMonthCount'   => $deliveredThisMonthCount,
            'commissionDeliveredMonthValue'   => $deliveredThisMonthSaleValue,
            'commissionDeliveredMonthIncome'  => $deliveredThisMonthCommission,
            'commissionDeliveredVendorIncome' => $deliveredVendorCommission,
            'commissionDeliveredCustomerIncome' => $deliveredCustomerCommission,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // INVENTORY SNAPSHOT — unaffected by the rebuild (still reads live
    // stock_quantity directly).
    // ─────────────────────────────────────────────────────────────
    private function inventorySnapshot(): array
    {
        $totalProducts   = Product::count();
        $outOfStockCount = (int) DB::table('product_variations')->where('stock_quantity', '<=', 0)->count();

        return [
            'totalProducts'   => $totalProducts,
            'outOfStockCount' => $outOfStockCount,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // OVERDUE INVOICES — in-app only, no outside notification.
    // Pulls every Credit-terms invoice across Purchase, Sale, and
    // Commission whose due date has passed.
    //
    // Sale tracks amount_received, so its list is filtered to invoices
    // still actually owing a balance. Purchase and Commission have no
    // partial-payment tracking of their own (settled via the general
    // Payment Voucher system) — their "overdue" here means the due
    // date passed on the full invoice amount, not that it's confirmed
    // unpaid. Flagged in the widget itself so it isn't misread as a
    // guaranteed-unpaid list.
    // ─────────────────────────────────────────────────────────────
    private function overdueInvoices(): array
    {
        $now = Carbon::now();

        $overduePurchases = PurchaseInvoice::with('vendor')
            ->where('payment_terms', 'credit')
            ->where('status', PurchaseInvoice::STATUS_RECEIVED)
            ->whereNotNull('credit_days')
            ->whereNotNull('received_at')
            ->get()
            ->filter(fn ($p) => $p->dueDate() && $now->greaterThan($p->dueDate()))
            ->map(fn ($p) => (object)[
                'id'         => $p->id,
                'invoice_no' => $p->invoice_no,
                'party'      => $p->vendor->name ?? '',
                'amount'     => $p->totalBillAmount(),
                'due_date'   => $p->dueDate(),
                'days_overdue' => $p->dueDate()->diffInDays($now),
                'route'      => route('purchase_invoices.show', $p->id),
            ])
            ->sortByDesc('days_overdue')
            ->values();

        $overdueSales = SaleInvoice::with('account')
            ->where('type', 'credit')
            ->whereNotNull('credit_days')
            ->get()
            ->filter(fn ($s) => $s->dueDate() && $now->greaterThan($s->dueDate()) && $s->remainingBalance() > 0.01)
            ->map(fn ($s) => (object)[
                'id'         => $s->id,
                'invoice_no' => $s->invoice_no,
                'party'      => $s->account->name ?? '',
                'amount'     => $s->remainingBalance(),
                'due_date'   => $s->dueDate(),
                'days_overdue' => $s->dueDate()->diffInDays($now),
                'route'      => route('sale_invoices.show', $s->id),
            ])
            ->sortByDesc('days_overdue')
            ->values();

        $overdueCommissions = CommissionInvoice::with('customer')
            ->where('payment_terms', 'credit')
            ->where('status', CommissionInvoice::STATUS_DELIVERED)
            ->whereNotNull('credit_days')
            ->whereNotNull('delivered_at')
            ->get()
            ->filter(fn ($c) => $c->dueDate() && $now->greaterThan($c->dueDate()))
            ->map(fn ($c) => (object)[
                'id'         => $c->id,
                'invoice_no' => $c->invoice_no,
                'party'      => $c->customer->name ?? '',
                'amount'     => $c->totalCustomerReceivable(),
                'due_date'   => $c->dueDate(),
                'days_overdue' => $c->dueDate()->diffInDays($now),
                'route'      => route('commission_invoices.show', $c->id),
            ])
            ->sortByDesc('days_overdue')
            ->values();

        return [
            'overduePurchases'    => $overduePurchases,
            'overdueSales'        => $overdueSales,
            'overdueCommissions'  => $overdueCommissions,
            'overdueTotalCount'   => $overduePurchases->count() + $overdueSales->count() + $overdueCommissions->count(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // RECENT ACTIVITY — last 5 of each invoice type
    // ─────────────────────────────────────────────────────────────
    private function recentActivity(): array
    {
        $recentPurchases    = PurchaseInvoice::with('vendor')->latest('invoice_date')->take(5)->get();
        $recentSales        = SaleInvoice::with('account')->latest('date')->take(5)->get();
        $recentCommissions  = CommissionInvoice::with(['vendor', 'customer'])->latest('invoice_date')->take(5)->get();

        return [
            'recentPurchases'   => $recentPurchases,
            'recentSales'       => $recentSales,
            'recentCommissions' => $recentCommissions,
        ];
    }
}