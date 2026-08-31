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
            $this->recentActivity()
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // FINANCIAL SNAPSHOT — cash/bank position, company-wide
    // receivables/payables (net across all customer/vendor accounts —
    // a dashboard KPI, not an audit-grade per-party figure; see
    // Accounts Reports > Receivables/Payables for the party-wise
    // breakdown).
    // ─────────────────────────────────────────────────────────────
    private function financialSnapshot($today, $monthStart, $monthEnd): array
    {
        $cashBankIds = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->pluck('id');
        $customerIds = ChartOfAccounts::where('account_type', 'customer')->pluck('id');
        $vendorIds   = ChartOfAccounts::where('account_type', 'vendor')->pluck('id');

        $cashBankBalance = $this->netBalance($cashBankIds, true);
        $totalReceivables = $this->netBalance($customerIds, true);
        $totalPayables    = $this->netBalance($vendorIds, false);

        $commissionIncomeMonth = Voucher::where('reference', 'like', 'CI-%-DELIVERED-COMMISSION')
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
     *
     * $debitNature = true  -> balance = debit - credit  (cash, bank, customer/AR)
     * $debitNature = false -> balance = credit - debit  (vendor/AP)
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
    // ─────────────────────────────────────────────────────────────
    private function salesSnapshot($today, $monthStart, $monthEnd): array
    {
        $todaySales = (float) SaleInvoice::whereDate('date', $today)->sum('net_amount');
        $monthSales = (float) SaleInvoice::whereBetween('date', [$monthStart, $monthEnd])->sum('net_amount');

        $cashSalesMonth   = (float) SaleInvoice::where('type', 'cash')
            ->whereBetween('date', [$monthStart, $monthEnd])->sum('net_amount');
        $creditSalesMonth = (float) SaleInvoice::where('type', 'credit')
            ->whereBetween('date', [$monthStart, $monthEnd])->sum('net_amount');

        $outstandingReceivables = (float) DB::table('sale_invoices')
            ->whereColumn('net_amount', '>', 'amount_received')
            ->sum(DB::raw('net_amount - amount_received'));

        $monthCogs = (float) DB::table('sale_invoice_items as sii')
            ->join('sale_invoices as si', 'sii.sale_invoice_id', '=', 'si.id')
            ->whereBetween('si.date', [$monthStart, $monthEnd])
            ->sum(DB::raw('sii.unit_cost * sii.quantity'));

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
            $receivedThisMonth->sum('total_amount') + $receivedThisMonth->sum('bilty_charges')
            + $receivedThisMonth->sum('labor_charges') + $receivedThisMonth->sum('other_charges'),
            2
        );

        $shortageInvoicesThisMonth = PurchaseInvoice::where('status', PurchaseInvoice::STATUS_RECEIVED)
            ->whereBetween('received_at', [$monthStart, $monthEnd])
            ->whereHas('items', fn ($q) => $q->where('short_quantity', '>', 0))
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
    // ─────────────────────────────────────────────────────────────
    private function commissionPipeline($monthStart, $monthEnd): array
    {
        $pendingCount = CommissionInvoice::where('status', CommissionInvoice::STATUS_PENDING)->count();

        $inTransitCount = CommissionInvoice::where('status', CommissionInvoice::STATUS_IN_TRANSIT)->count();
        $inTransitValue = (float) CommissionInvoice::where('status', CommissionInvoice::STATUS_IN_TRANSIT)->sum('total_purchase_amount');

        $deliveredThisMonth = CommissionInvoice::where('status', CommissionInvoice::STATUS_DELIVERED)
            ->whereBetween('delivered_at', [$monthStart, $monthEnd])->get();

        $deliveredThisMonthCount     = $deliveredThisMonth->count();
        $deliveredThisMonthSaleValue = (float) $deliveredThisMonth->sum('total_sale_amount');
        $deliveredThisMonthCommission = (float) $deliveredThisMonth->sum('total_commission_amount');

        return [
            'commissionPendingCount'         => $pendingCount,
            'commissionInTransitCount'       => $inTransitCount,
            'commissionInTransitValue'       => $inTransitValue,
            'commissionDeliveredMonthCount'  => $deliveredThisMonthCount,
            'commissionDeliveredMonthValue'  => $deliveredThisMonthSaleValue,
            'commissionDeliveredMonthIncome' => $deliveredThisMonthCommission,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // INVENTORY SNAPSHOT
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
    // RECENT ACTIVITY — last 5 of each invoice type
    // ─────────────────────────────────────────────────────────────
    private function recentActivity(): array
    {
        $recentPurchases = PurchaseInvoice::with('vendor')->latest('invoice_date')->take(5)->get();
        $recentSales      = SaleInvoice::with('account')->latest('date')->take(5)->get();
        $recentCommissions = CommissionInvoice::with(['vendor', 'customer'])->latest('invoice_date')->take(5)->get();

        return [
            'recentPurchases'   => $recentPurchases,
            'recentSales'       => $recentSales,
            'recentCommissions' => $recentCommissions,
        ];
    }
}