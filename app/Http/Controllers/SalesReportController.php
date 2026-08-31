<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use Carbon\Carbon;

class SalesReportController extends Controller
{
    public function saleReports(Request $request)
    {
        $tab = $request->get('tab', 'SR');

        $from = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $to   = $request->get('to_date', Carbon::now()->toDateString());

        $customerId = $request->get('customer_id');
        $type       = $request->get('type'); // '', cash, credit

        $sales           = collect();
        $returns         = collect();
        $customerWise    = collect();
        $productWise     = collect();
        $outstanding     = collect();
        $paymentAccountWise = collect();

        /* ================= SALES REGISTER =================
         * Now uses the invoice's own stored net_amount, amount_received,
         * and each item's stored unit_cost (snapshotted at time of sale)
         * instead of recomputing revenue from scratch — the old version's
         * recompute silently ignored per-item discount % and never had
         * real cost/profit data (they were hardcoded placeholders).
         */
        if ($tab === 'SR') {
            $query = SaleInvoice::with(['account', 'items'])
                ->whereBetween('date', [$from, $to]);

            if ($customerId) {
                $query->where('account_id', $customerId);
            }
            if ($type) {
                $query->where('type', $type);
            }

            $sales = $query->get()->map(function ($sale) {
                $cogs   = $sale->items->sum(fn ($item) => (float) $item->unit_cost * (float) $item->quantity);
                $net    = (float) $sale->net_amount;
                $profit = $net - $cogs;

                return (object)[
                    'id'              => $sale->id,
                    'date'            => $sale->date,
                    'invoice_no'      => $sale->invoice_no,
                    'customer'        => $sale->account->name ?? '',
                    'type'            => $sale->type,
                    'net_amount'      => $net,
                    'amount_received' => (float) $sale->amount_received,
                    'balance'         => round($net - (float) $sale->amount_received, 2),
                    'cogs'            => $cogs,
                    'profit'          => $profit,
                    'margin'          => $net > 0 ? round(($profit / $net) * 100, 1) : 0,
                ];
            });
        }

        /* ================= SALES RETURN ================= */
        if ($tab === 'SRET') {
            $returns = SaleReturn::with(['customer', 'items'])
                ->whereBetween('return_date', [$from, $to])
                ->get()
                ->map(function ($ret) {

                    $total = $ret->items->sum(function ($item) {
                        return $item->qty * $item->price;
                    });

                    return (object)[
                        'date'     => $ret->return_date,
                        'invoice'  => $ret->invoice_no ?? $ret->id,
                        'customer' => $ret->customer->name ?? '',
                        'total'    => $total,
                    ];
                });
        }

        /* ================= CUSTOMER WISE =================
         * Now also breaks out amount received vs outstanding, and total
         * COGS/profit per customer — not just a revenue total.
         */
        if ($tab === 'CW') {
            $query = SaleInvoice::with(['account', 'items'])
                ->whereBetween('date', [$from, $to]);

            if ($customerId) {
                $query->where('account_id', $customerId);
            }

            $customerWise = $query->get()
                ->groupBy('account_id')
                ->map(function ($sales) {
                    $customerName = $sales->first()->account->name ?? 'Unknown Customer';

                    $totalNet      = $sales->sum(fn ($s) => (float) $s->net_amount);
                    $totalReceived = $sales->sum(fn ($s) => (float) $s->amount_received);
                    $totalCogs     = $sales->sum(fn ($s) => $s->items->sum(fn ($i) => (float) $i->unit_cost * (float) $i->quantity));

                    return (object)[
                        'customer'         => $customerName,
                        'count'            => $sales->count(),
                        'total'            => $totalNet,
                        'total_received'   => $totalReceived,
                        'total_outstanding'=> round($totalNet - $totalReceived, 2),
                        'total_cogs'       => $totalCogs,
                        'total_profit'     => round($totalNet - $totalCogs, 2),
                    ];
                })
                ->values();
        }

        /* ================= PRODUCT WISE (NEW) ================= */
        if ($tab === 'PW') {
            $query = SaleInvoice::with('items.product')
                ->whereBetween('date', [$from, $to]);

            if ($customerId) {
                $query->where('account_id', $customerId);
            }

            $allItems = $query->get()->flatMap->items;

            $productWise = $allItems
                ->groupBy('product_id')
                ->map(function ($items) {
                    $productName = $items->first()->product->name ?? 'Unknown Product';
                    $qty      = $items->sum('quantity');
                    $revenue  = $items->sum('total');
                    $cogs     = $items->sum(fn ($i) => (float) $i->unit_cost * (float) $i->quantity);
                    $profit   = $revenue - $cogs;

                    return (object)[
                        'product'  => $productName,
                        'quantity' => $qty,
                        'revenue'  => $revenue,
                        'cogs'     => $cogs,
                        'profit'   => $profit,
                        'margin'   => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0,
                    ];
                })
                ->sortByDesc('revenue')
                ->values();
        }

        /* ================= OUTSTANDING RECEIVABLES (NEW) =================
         * Deliberately NOT date-filtered by default — an old unpaid
         * invoice from last month is still outstanding today. Date
         * filters here apply to the invoice date only if explicitly set
         * via the form; customer filter still applies.
         */
        if ($tab === 'OUT') {
            $query = SaleInvoice::with('account')
                ->whereColumn('net_amount', '>', 'amount_received');

            if ($request->filled('from_date') && $request->filled('to_date')) {
                $query->whereBetween('date', [$from, $to]);
            }
            if ($customerId) {
                $query->where('account_id', $customerId);
            }

            $outstanding = $query->orderBy('date')->get()->map(function ($sale) {
                $balance = round((float) $sale->net_amount - (float) $sale->amount_received, 2);
                return (object)[
                    'id'          => $sale->id,
                    'date'        => $sale->date,
                    'invoice_no'  => $sale->invoice_no,
                    'customer'    => $sale->account->name ?? '',
                    'type'        => $sale->type,
                    'net_amount'  => (float) $sale->net_amount,
                    'received'    => (float) $sale->amount_received,
                    'balance'     => $balance,
                    'days_outstanding' => Carbon::parse($sale->date)->diffInDays(Carbon::now()),
                ];
            });
        }

        /* ================= PAYMENT ACCOUNT WISE (NEW) =================
         * Groups every Sale receipt voucher (SI-*-RECEIPT-*) by the
         * account money was actually received into (Cash, Bank, etc).
         */
        if ($tab === 'PAY') {
            $query = Voucher::where('reference', 'like', 'SI-%-RECEIPT-%')
                ->whereBetween('date', [$from, $to]);

            $paymentAccountWise = $query->get()
                ->groupBy('ac_dr_sid')
                ->map(function ($vouchers, $accountId) {
                    $account = ChartOfAccounts::find($accountId);
                    return (object)[
                        'account_name' => $account->name ?? 'Unknown Account',
                        'count'        => $vouchers->count(),
                        'total'        => $vouchers->sum('amount'),
                    ];
                })
                ->sortByDesc('total')
                ->values();
        }

        $customers = ChartOfAccounts::where('account_type', 'customer')->get();

        return view('reports.sales_reports', compact(
            'tab',
            'from',
            'to',
            'sales',
            'returns',
            'customerWise',
            'productWise',
            'outstanding',
            'paymentAccountWise',
            'customers',
            'customerId',
            'type'
        ));
    }
}