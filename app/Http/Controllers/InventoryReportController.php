<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryReportController extends Controller
{
    public function inventoryReports(Request $request)
    {
        $tab     = $request->get('tab', 'IL');
        $itemId  = $request->get('item_id');
        $from    = $request->get('from_date', date('Y-m-01'));
        $to      = $request->get('to_date', date('Y-m-d'));

        // Purchase Return / Sale Return are a separate module that may not
        // be migrated in every environment yet. Every query touching those
        // tables is guarded by these flags so this report degrades to
        // "0 returns" instead of crashing when they don't exist.
        $hasPurchaseReturns = Schema::hasTable('purchase_returns') && Schema::hasTable('purchase_return_items');
        $hasSaleReturns     = Schema::hasTable('sale_returns') && Schema::hasTable('sale_return_items');

        $products    = Product::with('variations')->orderBy('name')->get();
        $itemLedger  = collect();
        $openingQty  = 0;
        $stockInHand = collect();
        $stockInTransit = collect();
        $commissionInTransit = collect();

        // ================================================================
        // TAB 1 — ITEM LEDGER
        //
        // IMPORTANT: only RECEIVED purchase quantities count as stock
        // movement now that Purchase has a Pending -> In Transit -> Received
        // workflow. Ordered quantity (the old 'quantity' column) is no
        // longer what enters inventory — 'received_quantity' is, and only
        // once status = 'received'. Dated by received_at (when stock
        // actually moved), not invoice_date (when the order was placed).
        // ================================================================
        if ($tab === 'IL' && $itemId) {

            $opPurchased = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->where('purchase_invoice_items.item_id', $itemId)
                ->where('purchase_invoices.status', 'received')
                ->whereNull('purchase_invoices.deleted_at')
                ->where('purchase_invoices.received_at', '<', $from)
                ->sum('purchase_invoice_items.received_quantity');

            $opSold = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->where('sale_invoice_items.product_id', $itemId)
                ->where('sale_invoices.date', '<', $from)
                ->sum('sale_invoice_items.quantity');

            $opPurchaseReturned = $hasPurchaseReturns
                ? DB::table('purchase_return_items')
                    ->join('purchase_returns', 'purchase_return_items.purchase_return_id', '=', 'purchase_returns.id')
                    ->where('purchase_return_items.item_id', $itemId)
                    ->where('purchase_returns.return_date', '<', $from)
                    ->sum('purchase_return_items.quantity')
                : 0;

            $opSaleReturned = $hasSaleReturns
                ? DB::table('sale_return_items')
                    ->join('sale_returns', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
                    ->where('sale_return_items.product_id', $itemId)
                    ->where('sale_returns.return_date', '<', $from)
                    ->sum('sale_return_items.qty')
                : 0;

            $openingQty = ((float)$opPurchased + (float)$opSaleReturned)
                        - ((float)$opSold      + (float)$opPurchaseReturned);

            $purchases = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->select(
                    'purchase_invoices.received_at as date',
                    DB::raw("'Purchase' as type"),
                    DB::raw("CONCAT('PI-', purchase_invoices.invoice_no) as description"),
                    'purchase_invoice_items.received_quantity as qty_in',
                    DB::raw('0 as qty_out')
                )
                ->where('purchase_invoice_items.item_id', $itemId)
                ->where('purchase_invoices.status', 'received')
                ->whereNull('purchase_invoices.deleted_at')
                ->whereBetween('purchase_invoices.received_at', [$from, $to]);

            // Shortage recorded at receiving time is its own traceable
            // ledger line — the stock that was dispatched but never
            // actually arrived.
            $shortages = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->select(
                    'purchase_invoices.received_at as date',
                    DB::raw("'Shortage' as type"),
                    DB::raw("CONCAT('PI-', purchase_invoices.invoice_no, ' (Shortage)') as description"),
                    DB::raw('0 as qty_in'),
                    'purchase_invoice_items.short_quantity as qty_out'
                )
                ->where('purchase_invoice_items.item_id', $itemId)
                ->where('purchase_invoices.status', 'received')
                ->where('purchase_invoice_items.short_quantity', '>', 0)
                ->whereNull('purchase_invoices.deleted_at')
                ->whereBetween('purchase_invoices.received_at', [$from, $to]);

            $sales = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->select(
                    'sale_invoices.date as date',
                    DB::raw("'Sale' as type"),
                    DB::raw("CONCAT('SI-', sale_invoices.invoice_no) as description"),
                    DB::raw('0 as qty_in'),
                    'sale_invoice_items.quantity as qty_out'
                )
                ->where('sale_invoice_items.product_id', $itemId)
                ->whereBetween('sale_invoices.date', [$from, $to]);

            $itemLedger = $purchases->union($shortages)->union($sales);

            if ($hasPurchaseReturns) {
                $purchaseReturns = DB::table('purchase_return_items')
                    ->join('purchase_returns', 'purchase_return_items.purchase_return_id', '=', 'purchase_returns.id')
                    ->select(
                        'purchase_returns.return_date as date',
                        DB::raw("'Purchase Return' as type"),
                        DB::raw("CONCAT('PR-', purchase_returns.id) as description"),
                        DB::raw('0 as qty_in'),
                        'purchase_return_items.quantity as qty_out'
                    )
                    ->where('purchase_return_items.item_id', $itemId)
                    ->whereBetween('purchase_returns.return_date', [$from, $to]);

                $itemLedger = $itemLedger->union($purchaseReturns);
            }

            if ($hasSaleReturns) {
                $saleReturns = DB::table('sale_return_items')
                    ->join('sale_returns', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
                    ->select(
                        'sale_returns.return_date as date',
                        DB::raw("'Sale Return' as type"),
                        DB::raw("CONCAT('SR-', sale_returns.id) as description"),
                        'sale_return_items.qty as qty_in',
                        DB::raw('0 as qty_out')
                    )
                    ->where('sale_return_items.product_id', $itemId)
                    ->whereBetween('sale_returns.return_date', [$from, $to]);

                $itemLedger = $itemLedger->union($saleReturns);
            }

            $itemLedger = $itemLedger
                ->orderBy('date', 'asc')
                ->get()
                ->map(fn($row) => (array) $row);
        }

        // ================================================================
        // TAB 2 — STOCK IN HAND
        //
        // Same fix as Item Ledger: only RECEIVED purchase quantities count
        // toward on-hand stock. A Purchase Invoice sitting at Pending or
        // In Transit has NOT physically arrived and must not inflate
        // available stock.
        // ================================================================
        if ($tab === 'SR') {

            $productQuery = Product::with('variations')
                ->leftJoin('measurement_units', 'measurement_units.id', '=', 'products.measurement_unit')
                ->select('products.*', 'measurement_units.shortcode as unit_shortcode')
                ->orderBy('products.name');

            if ($itemId) {
                $productQuery->where('products.id', $itemId);
            }

            $productRows = $productQuery->get();

            foreach ($productRows as $product) {

                $hasVariations = $product->variations->isNotEmpty();

                if (!$hasVariations) {

                    $purchased = (float) DB::table('purchase_invoice_items')
                        ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                        ->where('purchase_invoice_items.item_id', $product->id)
                        ->where('purchase_invoices.status', 'received')
                        ->whereNull('purchase_invoices.deleted_at')
                        ->sum('purchase_invoice_items.received_quantity');

                    $sold = (float) DB::table('sale_invoice_items')
                        ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                        ->where('sale_invoice_items.product_id', $product->id)
                        ->sum('sale_invoice_items.quantity');

                    $purchaseReturned = $hasPurchaseReturns
                        ? (float) DB::table('purchase_return_items')->where('item_id', $product->id)->sum('quantity')
                        : 0.0;

                    $saleReturned = $hasSaleReturns
                        ? (float) DB::table('sale_return_items')->where('product_id', $product->id)->sum('qty')
                        : 0.0;

                    $qty = ($purchased + $saleReturned) - ($sold + $purchaseReturned);

                    if ($qty > 0) {
                        $stockInHand->push([
                            'product'   => $product->name,
                            'variation' => '—',
                            'quantity'  => $qty,
                            'unit'      => $product->unit_shortcode ?? '',
                        ]);
                    }

                } else {

                    // Catch-all row: received purchases/sales where variation_id is null
                    $nullQtyIn = (float) DB::table('purchase_invoice_items')
                        ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                        ->where('purchase_invoice_items.item_id', $product->id)
                        ->whereNull('purchase_invoice_items.variation_id')
                        ->where('purchase_invoices.status', 'received')
                        ->whereNull('purchase_invoices.deleted_at')
                        ->sum('purchase_invoice_items.received_quantity');

                    $nullQtyOut = (float) DB::table('sale_invoice_items')
                        ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                        ->where('sale_invoice_items.product_id', $product->id)
                        ->whereNull('sale_invoice_items.variation_id')
                        ->sum('sale_invoice_items.quantity');

                    $nullPR = $hasPurchaseReturns
                        ? (float) DB::table('purchase_return_items')->where('item_id', $product->id)->whereNull('variation_id')->sum('quantity')
                        : 0.0;

                    $nullSR = $hasSaleReturns
                        ? (float) DB::table('sale_return_items')->where('product_id', $product->id)->whereNull('variation_id')->sum('qty')
                        : 0.0;

                    $nullQty = ($nullQtyIn + $nullSR) - ($nullQtyOut + $nullPR);

                    if ($nullQty > 0) {
                        $stockInHand->push([
                            'product'   => $product->name,
                            'variation' => 'No Variation',
                            'quantity'  => $nullQty,
                            'unit'      => $product->unit_shortcode ?? '',
                        ]);
                    }

                    foreach ($product->variations as $v) {

                        $vPurchased = (float) DB::table('purchase_invoice_items')
                            ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                            ->where('purchase_invoice_items.item_id', $product->id)
                            ->where('purchase_invoice_items.variation_id', $v->id)
                            ->where('purchase_invoices.status', 'received')
                            ->whereNull('purchase_invoices.deleted_at')
                            ->sum('purchase_invoice_items.received_quantity');

                        $vSold = (float) DB::table('sale_invoice_items')
                            ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                            ->where('sale_invoice_items.product_id', $product->id)
                            ->where('sale_invoice_items.variation_id', $v->id)
                            ->sum('sale_invoice_items.quantity');

                        $vPR = $hasPurchaseReturns
                            ? (float) DB::table('purchase_return_items')->where('item_id', $product->id)->where('variation_id', $v->id)->sum('quantity')
                            : 0.0;

                        $vSR = $hasSaleReturns
                            ? (float) DB::table('sale_return_items')->where('product_id', $product->id)->where('variation_id', $v->id)->sum('qty')
                            : 0.0;

                        $vQty = ($vPurchased + $vSR) - ($vSold + $vPR);

                        if ($vQty > 0) {
                            $stockInHand->push([
                                'product'   => $product->name,
                                'variation' => $v->sku ?? $v->name ?? '—',
                                'quantity'  => $vQty,
                                'unit'      => $product->unit_shortcode ?? '',
                            ]);
                        }
                    }
                }
            }
        }

        // ================================================================
        // TAB 3 — STOCK IN TRANSIT (Purchase)
        //
        // Goods a Vendor has dispatched (status = in_transit) but that
        // haven't been physically received yet. Tracked entirely from
        // purchase_invoice_items.dispatched_quantity — these have NOT hit
        // ProductVariation.stock_quantity and are not counted in Stock In
        // Hand above, by design (they're a distinct, separately reported
        // asset — see the Purchase module's 'Inventory In Transit' account).
        // ================================================================
        if ($tab === 'IT') {

            $query = DB::table('purchase_invoice_items as pii')
                ->join('purchase_invoices as pi', 'pii.purchase_invoice_id', '=', 'pi.id')
                ->join('products as p', 'pii.item_id', '=', 'p.id')
                ->leftJoin('product_variations as pv', 'pii.variation_id', '=', 'pv.id')
                ->join('chart_of_accounts as v', 'pi.vendor_id', '=', 'v.id')
                ->select(
                    'pi.id as invoice_id',
                    'pi.invoice_no',
                    'pi.invoice_date',
                    'pi.bill_no as vendor_bill_no',
                    'pi.bilty_no',
                    'v.name as vendor_name',
                    'p.name as product_name',
                    'pv.sku as variation_sku',
                    'pii.dispatched_quantity',
                    'pii.price'
                )
                ->where('pi.status', 'in_transit')
                ->whereNull('pi.deleted_at');

            if ($itemId) {
                $query->where('pii.item_id', $itemId);
            }

            $stockInTransit = $query->orderBy('pi.invoice_date', 'desc')->get()->map(function ($row) {
                $row->dispatched_value = (float) $row->dispatched_quantity * (float) $row->price;
                return $row;
            });
        }

        // ================================================================
        // TAB 4 — COMMISSION GOODS IN TRANSIT
        //
        // Goods procured specifically for a Customer under a Commission /
        // Brokerage Invoice, dispatched by the Vendor (status = in_transit)
        // but not yet delivered. These NEVER touch ProductVariation stock
        // or the regular Purchase 'Inventory In Transit' account — kept
        // fully separate per the brokerage accounting design.
        // ================================================================
        if ($tab === 'CIT') {

            $query = DB::table('commission_invoice_items as cii')
                ->join('commission_invoices as ci', 'cii.commission_invoice_id', '=', 'ci.id')
                ->join('products as p', 'cii.product_id', '=', 'p.id')
                ->leftJoin('product_variations as pv', 'cii.variation_id', '=', 'pv.id')
                ->join('chart_of_accounts as v', 'ci.vendor_id', '=', 'v.id')
                ->join('chart_of_accounts as c', 'ci.customer_id', '=', 'c.id')
                ->select(
                    'ci.id as invoice_id',
                    'ci.invoice_no',
                    'ci.invoice_date',
                    'ci.vendor_bill_no',
                    'ci.bilty_no',
                    'ci.transport_name',
                    'v.name as vendor_name',
                    'c.name as customer_name',
                    'p.name as product_name',
                    'pv.sku as variation_sku',
                    'cii.quantity',
                    'cii.weight',
                    'cii.purchase_total',
                    'cii.sale_total'
                )
                ->where('ci.status', 'in_transit')
                ->whereNull('ci.deleted_at');

            if ($itemId) {
                $query->where('cii.product_id', $itemId);
            }

            $commissionInTransit = $query->orderBy('ci.invoice_date', 'desc')->get();
        }

        return view('reports.inventory_reports', compact(
            'products',
            'itemLedger',
            'openingQty',
            'stockInHand',
            'stockInTransit',
            'commissionInTransit',
            'tab',
            'from',
            'to'
        ));
    }
}