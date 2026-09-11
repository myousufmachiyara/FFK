<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use Illuminate\Support\Facades\Schema;
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
        $expenseReport   = collect();

        $hasSaleReturns = Schema::hasTable('sale_returns') && Schema::hasTable('sale_return_items');

        /* ================= SALES REGISTER =================
         * FIX: COGS now multiplies unit_cost by net_weight (kg), not
         * quantity (which is packing-unit count since the weight-based
         * rebuild — multiplying cost-per-kg by bag count silently produced
         * near-zero, meaningless COGS). Balance now compares against
         * totalBillAmount() (items + expenses), not net_amount alone —
         * an invoice can be "paid off" on items but still owe expenses.
         */
        if ($tab === 'SR') {
            $query = SaleInvoice::with(['account', 'items', 'expenses'])
                ->whereBetween('date', [$from, $to]);

            if ($customerId) {
                $query->where('account_id', $customerId);
            }
            if ($type) {
                $query->where('type', $type);
            }

            $sales = $query->get()->map(function ($sale) {
                $cogs   = $sale->items->sum(fn ($item) => (float) $item->unit_cost * (float) $item->net_weight);
                $net    = (float) $sale->net_amount;
                $profit = $net - $cogs;
                $billAmount = $sale->totalBillAmount();

                return (object)[
                    'id'              => $sale->id,
                    'date'            => $sale->date,
                    'invoice_no'      => $sale->invoice_no,
                    'customer'        => $sale->account->name ?? '',
                    'type'            => $sale->type,
                    'net_amount'      => $net,
                    'total_expenses'  => (float) $sale->total_other_expenses,
                    'bill_amount'     => $billAmount,
                    'amount_received' => (float) $sale->amount_received,
                    'balance'         => round($billAmount - (float) $sale->amount_received, 2),
                    'net_weight'      => (float) $sale->total_weight,
                    'gross_weight'    => (float) $sale->total_gross_weight,
                    'cogs'            => $cogs,
                    'profit'          => $profit,
                    'margin'          => $net > 0 ? round(($profit / $net) * 100, 1) : 0,
                ];
            });
        }

        /* ================= SALES RETURN ================= */
        if ($tab === 'SRET' && $hasSaleReturns) {
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
         * FIX: same COGS fix (net_weight, not quantity). Outstanding now
         * measured against totalBillAmount() (items + expenses) per
         * invoice, then summed — not (sum of net_amount) - (sum received),
         * which ignored expenses entirely.
         */
        if ($tab === 'CW') {
            $query = SaleInvoice::with(['account', 'items', 'expenses'])
                ->whereBetween('date', [$from, $to]);

            if ($customerId) {
                $query->where('account_id', $customerId);
            }

            $customerWise = $query->get()
                ->groupBy('account_id')
                ->map(function ($sales) {
                    $customerName = $sales->first()->account->name ?? 'Unknown Customer';

                    $totalNet         = $sales->sum(fn ($s) => (float) $s->net_amount);
                    $totalExpenses    = $sales->sum(fn ($s) => (float) $s->total_other_expenses);
                    $totalBillAmount  = $sales->sum(fn ($s) => $s->totalBillAmount());
                    $totalReceived    = $sales->sum(fn ($s) => (float) $s->amount_received);
                    $totalCogs        = $sales->sum(fn ($s) => $s->items->sum(fn ($i) => (float) $i->unit_cost * (float) $i->net_weight));

                    return (object)[
                        'customer'          => $customerName,
                        'count'             => $sales->count(),
                        'total'             => $totalNet,
                        'total_expenses'    => $totalExpenses,
                        'total_bill_amount' => $totalBillAmount,
                        'total_received'    => $totalReceived,
                        'total_outstanding' => round($totalBillAmount - $totalReceived, 2),
                        'total_cogs'        => $totalCogs,
                        'total_profit'      => round($totalNet - $totalCogs, 2),
                    ];
                })
                ->values();
        }

        /* ================= PRODUCT WISE =================
         * FIX: "quantity" now reports net_weight (kg) — the meaningful
         * figure for a weight-based commodity business — instead of
         * packing-unit (bag) count. COGS fixed to use net_weight too.
         */
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
                    $packingUnits = $items->sum('quantity');
                    $netWeight    = $items->sum('net_weight');
                    $revenue      = $items->sum('total');
                    $cogs         = $items->sum(fn ($i) => (float) $i->unit_cost * (float) $i->net_weight);
                    $profit       = $revenue - $cogs;

                    return (object)[
                        'product'       => $productName,
                        'quantity'      => $netWeight,      // kg — the meaningful figure now; kept this key name for blade compatibility
                        'packing_units' => $packingUnits,   // bag/carton count, available if the view wants it
                        'net_weight'    => $netWeight,
                        'revenue'       => $revenue,
                        'cogs'          => $cogs,
                        'profit'        => $profit,
                        'margin'        => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0,
                    ];
                })
                ->sortByDesc('revenue')
                ->values();
        }

        /* ================= OUTSTANDING RECEIVABLES =================
         * FIX: was comparing net_amount vs amount_received directly in
         * SQL, missing every invoice whose items were fully paid but
         * still owes expense charges. Now pulled in PHP against
         * totalBillAmount() so expenses are included. Still not
         * date-filtered by default — an old unpaid invoice stays
         * outstanding regardless of when it was raised.
         */
        if ($tab === 'OUT') {
            $query = SaleInvoice::with(['account', 'expenses']);

            if ($request->filled('from_date') && $request->filled('to_date')) {
                $query->whereBetween('date', [$from, $to]);
            }
            if ($customerId) {
                $query->where('account_id', $customerId);
            }

            $outstanding = $query->orderBy('date')->get()
                ->filter(fn ($sale) => $sale->totalBillAmount() > (float) $sale->amount_received)
                ->map(function ($sale) {
                    $billAmount = $sale->totalBillAmount();
                    $balance    = round($billAmount - (float) $sale->amount_received, 2);
                    return (object)[
                        'id'          => $sale->id,
                        'date'        => $sale->date,
                        'invoice_no'  => $sale->invoice_no,
                        'customer'    => $sale->account->name ?? '',
                        'type'        => $sale->type,
                        'net_amount'  => $billAmount, // kept this key name for blade compatibility — value is now the full bill (items + expenses), not items alone
                        'received'    => (float) $sale->amount_received,
                        'balance'     => $balance,
                        'due_date'    => $sale->dueDate(),
                        'days_outstanding' => Carbon::parse($sale->date)->diffInDays(Carbon::now()),
                    ];
                })
                ->values();
        }

        /* ================= PAYMENT ACCOUNT WISE =================
         * Unaffected by the rebuild — voucher reference pattern
         * (SI-*-RECEIPT-*) didn't change.
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

        /* ================= OTHER EXPENSES (NEW) =================
         * Sale's new Other Expenses table — grouped by expense type and
         * by payee account, so it's visible which transporters/accounts
         * are getting paid via customer-billed pass-through expenses.
         */
        if ($tab === 'EXP') {
            $query = \App\Models\SaleInvoiceExpense::with(['saleInvoice', 'payeeAccount'])
                ->whereHas('saleInvoice', function ($q) use ($from, $to, $customerId) {
                    $q->whereBetween('date', [$from, $to]);
                    if ($customerId) $q->where('account_id', $customerId);
                });

            $expenseReport = $query->get()->map(function ($exp) {
                return (object)[
                    'date'        => $exp->saleInvoice->date ?? null,
                    'invoice_no'  => $exp->saleInvoice->invoice_no ?? '',
                    'type'        => $exp->typeLabel(),
                    'description' => $exp->description,
                    'amount'      => (float) $exp->amount,
                    'payee'       => $exp->payeeAccount->name ?? '—',
                ];
            })->sortByDesc('date')->values();
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
            'expenseReport',
            'customers',
            'customerId',
            'type'
        ));
    }
}