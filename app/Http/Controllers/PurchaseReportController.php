<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\ChartOfAccounts;
use Carbon\Carbon;

class PurchaseReportController extends Controller
{
    public function purchaseReports(Request $request)
    {
        $tab  = $request->get('tab', 'PUR');
        $from = $request->get('from_date', Carbon::now()->startOfMonth()->toDateString());
        $to   = $request->get('to_date', Carbon::now()->toDateString());
        $status = $request->get('status', ''); // '', pending, in_transit, received

        $vendors = ChartOfAccounts::where('account_type', 'vendor')->get();

        $purchaseRegister   = collect();
        $purchaseReturns    = collect();
        $vendorWisePurchase = collect();
        $statusOverview     = collect();
        $shortagesAndCosts  = collect();

        /* ================= PURCHASE REGISTER =================
         * Register of purchase transactions as INVOICED (ordered qty/rate),
         * independent of workflow status — Status is now its own column
         * plus filter, rather than the report silently mixing Pending/
         * In Transit/Received rows with no way to tell them apart.
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
                        'invoice_id'      => $invoice->id,
                        'date'            => $invoice->invoice_date,
                        'invoice_no'      => $invoice->invoice_no,
                        'vendor_bill_no'  => $invoice->bill_no,
                        'vendor_name'     => $invoice->vendor->name ?? '',
                        'status'          => $invoice->status,
                        'item_name'       => $item->product->name ?? 'N/A',
                        'variation'       => $item->variation->sku ?? '-',
                        'quantity'        => $item->quantity,
                        'received_quantity' => $item->received_quantity,
                        'rate'            => $item->price,
                        'total'           => $item->quantity * $item->price,
                    ];
                });
            });
        }

        /* ================= PURCHASE RETURNS ================= */
        if ($tab === 'PR') {
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

        /* ================= VENDOR-WISE PURCHASE ================= */
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
                                'quantity'       => $item->quantity,
                                'rate'           => $item->price,
                                'total'          => $item->quantity * $item->price,
                            ]);
                        }
                    }

                    return (object)[
                        'vendor_name'  => $vendorName,
                        'items'        => $items,
                        'total_qty'    => $items->sum('quantity'),
                        'total_amount' => $items->sum('total'),
                    ];
                })
                ->values();
        }

        /* ================= STATUS OVERVIEW (NEW) =================
         * Pending / In Transit / Received — count + value per status,
         * for the date range. Received also breaks out additional
         * receiving costs (Bilty/Labor/Other) since those only exist
         * once an invoice reaches Received.
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
                    'status'          => $statusKey,
                    'label'           => ucwords(str_replace('_', ' ', $statusKey)),
                    'count'           => $group->count(),
                    'total_amount'    => $group->sum('total_amount'),
                    'bilty_charges'   => $group->sum('bilty_charges'),
                    'labor_charges'   => $group->sum('labor_charges'),
                    'other_charges'   => $group->sum('other_charges'),
                ]);
            }
        }

        /* ================= SHORTAGES & ADDITIONAL COSTS (NEW) =================
         * Received invoices where either a shortage was recorded on any
         * item, or any additional receiving cost (Bilty/Labor/Other) was
         * entered. Shows landed-cost impact per invoice.
         */
        if ($tab === 'SAC') {
            $query = PurchaseInvoice::with('items')
                ->where('status', PurchaseInvoice::STATUS_RECEIVED)
                ->whereBetween('received_at', [$from, $to]);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $shortagesAndCosts = $query->with('vendor')->get()
                ->filter(function ($invoice) {
                    $hasShortage = $invoice->items->sum('short_quantity') > 0;
                    $hasCharges  = $invoice->totalAdditionalCharges() > 0;
                    return $hasShortage || $hasCharges;
                })
                ->map(function ($invoice) {
                    $dispatchedQty = $invoice->items->sum(fn ($i) => $i->dispatched_quantity ?? $i->quantity);
                    $receivedQty   = $invoice->items->sum('received_quantity');
                    $shortQty      = $invoice->items->sum('short_quantity');
                    $shortageValue = $invoice->items->sum(fn ($i) => (float) $i->short_quantity * (float) $i->price);

                    return (object)[
                        'invoice_id'      => $invoice->id,
                        'invoice_no'      => $invoice->invoice_no,
                        'vendor_name'     => $invoice->vendor->name ?? '',
                        'received_at'     => $invoice->received_at,
                        'dispatched_qty'  => $dispatchedQty,
                        'received_qty'    => $receivedQty,
                        'short_qty'       => $shortQty,
                        'shortage_value'  => $shortageValue,
                        'bilty_charges'   => $invoice->bilty_charges,
                        'labor_charges'   => $invoice->labor_charges,
                        'other_charges'   => $invoice->other_charges,
                        'total_additional'=> $invoice->totalAdditionalCharges(),
                    ];
                })
                ->values();
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
            'shortagesAndCosts'
        ));
    }
}