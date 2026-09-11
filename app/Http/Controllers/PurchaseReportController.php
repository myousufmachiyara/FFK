<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\ChartOfAccounts;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class PurchaseReportController extends Controller
{
    public function purchaseReports(Request $request)
    {
        $tab  = $request->get('tab', 'PUR');
        $from = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $to   = $request->get('to_date', Carbon::now()->toDateString());
        $status = $request->get('status', ''); // '', pending, in_transit, received

        $hasPurchaseReturns = Schema::hasTable('purchase_returns') && Schema::hasTable('purchase_return_items');

        $vendors = ChartOfAccounts::where('account_type', 'vendor')->get();

        $purchaseRegister   = collect();
        $purchaseReturns    = collect();
        $vendorWisePurchase = collect();
        $statusOverview     = collect();
        $shortagesAndCosts  = collect();
        $expenseReport      = collect();

        /* ================= PURCHASE REGISTER =================
         * FIX: rewritten for the weight-based schema — item->amount
         * (already = rate_per_kg * net_weight, computed server-side) is
         * used directly instead of the old quantity*price, which would
         * now multiply a bag count by a per-kg rate — nonsensical unit
         * mixing left over from before the weight-based rebuild.
         */
        if ($tab === 'PUR') {
            $query = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation'])
                ->whereBetween('invoice_date', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $purchaseRegister = $query->get()->flatMap(function ($invoice) {
                return $invoice->items->map(function ($item) use ($invoice) {
                    return (object)[
                        'invoice_id'          => $invoice->id,
                        'date'                => $invoice->invoice_date,
                        'invoice_no'          => $invoice->invoice_no,
                        'vendor_bill_no'      => $invoice->bill_no,
                        'vendor_name'         => $invoice->vendor->name ?? '',
                        'status'              => $invoice->status,
                        'payment_terms'       => $invoice->payment_terms,
                        'due_date'            => $invoice->dueDate(),
                        'item_name'           => $item->product->name ?? 'N/A',
                        'variation'           => $item->variation->sku ?? '-',
                        'quantity'            => $item->quantity,
                        'gross_weight'        => $item->gross_weight,
                        'net_weight'          => $item->net_weight,
                        'received_net_weight' => $item->received_net_weight,
                        'received_quantity'   => $item->received_net_weight, // kept this key name for blade compatibility
                        'rate_per_kg'         => $item->price,
                        'total'               => $item->amount,
                    ];
                });
            });
        }

        /* ================= PURCHASE RETURNS =================
         * Guarded — this module was never migrated in every environment.
         */
        if ($tab === 'PR' && $hasPurchaseReturns) {
            $query = PurchaseReturn::with(['vendor', 'items.item'])
                ->whereBetween('return_date', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $purchaseReturns = $query->get()->flatMap(function ($return) {
                return $return->items->map(function ($item) use ($return) {
                    return (object)[
                        'return_id'   => $return->id,
                        'date'        => $return->return_date,
                        'return_no'   => $return->invoice_no,
                        'vendor_name' => $return->vendor->name ?? '',
                        'item_name'   => $item->item->name ?? 'N/A',
                        'quantity'    => $item->quantity,
                        'rate'        => $item->price,
                        'total'       => $item->quantity * $item->price,
                    ];
                });
            });
        }

        /* ================= VENDOR-WISE PURCHASE =================
         * FIX: same amount/weight fixes as Purchase Register.
         */
        if ($tab === 'VWP') {
            $query = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation'])
                ->whereBetween('invoice_date', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $vendorWisePurchase = $query->get()
                ->groupBy('vendor_id')
                ->map(function ($purchases) {
                    $vendorName = $purchases->first()->vendor->name ?? 'Unknown Vendor';
                    $items = collect();

                    foreach ($purchases as $invoice) {
                        foreach ($invoice->items as $item) {
                            $items->push((object)[
                                'invoice_date'   => $invoice->invoice_date,
                                'invoice_id'     => $invoice->id,
                                'invoice_no'     => $invoice->invoice_no,
                                'vendor_bill_no' => $invoice->bill_no,
                                'status'         => $invoice->status,
                                'item_name'      => $item->product->name ?? 'N/A',
                                'variation'      => $item->variation->sku ?? '-',
                                'net_weight'     => $item->net_weight,
                                'rate_per_kg'    => $item->price,
                                'total'          => $item->amount,
                            ]);
                        }
                    }

                    return (object)[
                        'vendor_name'        => $vendorName,
                        'items'              => $items,
                        'total_net_weight'   => $items->sum('net_weight'),
                        'total_amount'       => $items->sum('total'),
                    ];
                })
                ->values();
        }

        /* ================= STATUS OVERVIEW =================
         * FIX: bilty_charges/labor_charges/other_charges (legacy flat
         * fields) replaced by total_other_expenses (the dynamic
         * expenses table). Added total_gross_weight per status.
         */
        if ($tab === 'STA') {
            $query = PurchaseInvoice::whereBetween('invoice_date', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $invoices = $query->get();

            foreach ([
                PurchaseInvoice::STATUS_PENDING,
                PurchaseInvoice::STATUS_IN_TRANSIT,
                PurchaseInvoice::STATUS_RECEIVED,
            ] as $statusKey) {
                $group = $invoices->where('status', $statusKey);

                $statusOverview->push((object)[
                    'status'              => $statusKey,
                    'label'               => ucwords(str_replace('_', ' ', $statusKey)),
                    'count'               => $group->count(),
                    'total_amount'        => $group->sum('total_amount'),
                    'total_gross_weight'  => $group->sum('total_gross_weight'),
                    'total_net_weight'    => $group->sum('total_weight'),
                    'total_other_expenses'=> $group->sum('total_other_expenses'),
                    'total_bill_amount'   => $group->sum(fn ($i) => $i->totalBillAmount()),
                    // Legacy 3-way split no longer exists (replaced by the
                    // dynamic expenses list) — kept these keys for blade
                    // compatibility, with the full expense total folded
                    // into 'bilty_charges' so the view's existing
                    // total_amount + bilty + labor + other formula still
                    // adds up correctly. See Other Expenses tab for the
                    // real per-type breakdown.
                    'bilty_charges'       => $group->sum('total_other_expenses'),
                    'labor_charges'       => 0,
                    'other_charges'       => 0,
                ]);
            }
        }

        /* ================= SHORTAGES & ADDITIONAL COSTS =================
         * FIX: short_quantity -> short_weight, received_quantity ->
         * received_net_weight, dispatched_quantity (bag count) ->
         * net_weight (kg, the actual dispatched weight) — the old
         * version compared a bag count against a per-kg price, which
         * silently produced a meaningless "shortage_value".
         * bilty_charges/labor_charges/other_charges (legacy flat fields,
         * no longer written to) replaced by the dynamic expenses list.
         */
        if ($tab === 'SAC') {
            $query = PurchaseInvoice::with(['items', 'expenses.payeeAccount', 'vendor'])
                ->where('status', PurchaseInvoice::STATUS_RECEIVED)
                ->whereBetween('received_at', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $shortagesAndCosts = $query->get()
                ->filter(function ($invoice) {
                    $hasShortage = $invoice->items->sum('short_weight') > 0;
                    $hasCharges  = $invoice->total_other_expenses > 0;
                    return $hasShortage || $hasCharges;
                })
                ->map(function ($invoice) {
                    $dispatchedWeight = $invoice->items->sum('net_weight');
                    $receivedWeight   = $invoice->items->sum('received_net_weight');
                    $shortWeight      = $invoice->items->sum('short_weight');
                    $shortageValue    = $invoice->items->sum(fn ($i) => (float) $i->short_weight * (float) $i->price);

                    return (object)[
                        'invoice_id'        => $invoice->id,
                        'invoice_no'        => $invoice->invoice_no,
                        'vendor_name'       => $invoice->vendor->name ?? '',
                        'received_at'       => $invoice->received_at,
                        'dispatched_weight' => $dispatchedWeight,
                        'received_weight'   => $receivedWeight,
                        'short_weight'      => $shortWeight,
                        'shortage_value'    => $shortageValue,
                        'expenses'          => $invoice->expenses,
                        'total_other_expenses' => $invoice->total_other_expenses,
                        // Legacy keys kept for blade compatibility —
                        // dispatched_qty/received_qty/short_qty now carry
                        // weight (kg) values instead of bag counts, and
                        // the 3-way charge split is folded into
                        // bilty_charges the same way as the STA tab above.
                        'dispatched_qty'    => $dispatchedWeight,
                        'received_qty'      => $receivedWeight,
                        'short_qty'         => $shortWeight,
                        'bilty_charges'     => $invoice->total_other_expenses,
                        'labor_charges'     => 0,
                        'other_charges'     => 0,
                        'total_additional'  => $invoice->total_other_expenses,
                    ];
                })
                ->values();
        }

        /* ================= OTHER EXPENSES (NEW) =================
         * Purchase's expenses list — shown flat with paid-by/payee
         * breakdown, so it's visible at a glance which vendors vs which
         * named payee accounts (e.g. transporters) are owed what.
         */
        if ($tab === 'EXP') {
            $query = \App\Models\PurchaseInvoiceExpense::with(['purchaseInvoice.vendor', 'payeeAccount'])
                ->whereHas('purchaseInvoice', function ($q) use ($from, $to, $request) {
                    $q->whereBetween('invoice_date', [$from, $to]);
                    if ($request->filled('vendor_id')) $q->where('vendor_id', $request->vendor_id);
                });

            $expenseReport = $query->get()->map(function ($exp) {
                $payTo = $exp->paid_by === 'vendor'
                    ? ($exp->purchaseInvoice->vendor->name ?? '-')
                    : ($exp->payeeAccount->name ?? '—');

                return (object)[
                    'date'        => $exp->purchaseInvoice->invoice_date ?? null,
                    'invoice_no'  => $exp->purchaseInvoice->invoice_no ?? '',
                    'type'        => $exp->typeLabel(),
                    'description' => $exp->description,
                    'amount'      => (float) $exp->amount,
                    'paid_by'     => $exp->paidByLabel(),
                    'payable_to'  => $payTo,
                ];
            })->sortByDesc('date')->values();
        }

        return view('reports.purchase_reports', compact(
            'tab',
            'from',
            'to',
            'status',
            'vendors',
            'purchaseRegister',
            'purchaseReturns',
            'vendorWisePurchase',
            'statusOverview',
            'shortagesAndCosts',
            'expenseReport'
        ));
    }
}