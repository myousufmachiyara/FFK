<?php

namespace App\Services;

use App\Models\ChartOfAccounts;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\SaleInvoice;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps Sale COGS in line with the weighted-average landed purchase cost.
 *
 * WHY: a sale's unit_cost used to be a one-time snapshot taken when the
 * sale was saved. If a sale was entered before all purchases of that item
 * were received (or a purchase was later reverted / re-received), the
 * sale kept a stale cost forever — e.g. a 805/kg cost when the real
 * average across all lots was 782/kg — producing wrong (even negative)
 * profit on the Sale Invoice-wise profit report and in the COGS account.
 *
 * WHAT: for the given products, every sale line is re-costed at the
 * current weighted-average landed cost (all Received purchases, incl.
 * their allocated Other Expenses, plus opening stock if it has a rate),
 * then each affected invoice's SI-{id}-COGS voucher is updated, and any
 * Sale Return COGS reversals on those invoices are re-priced to match —
 * so a return still nets back exactly what the sale booked.
 *
 * Called automatically after Purchase receive / revert and Product
 * opening-rate changes; run for everything via `php artisan sales:recalculate-cogs`.
 */
class SaleCostRecalculator
{
    /** Weighted-average landed cost per kg; 0 = no cost basis. */
    public static function averageCost(int $productId, ?int $variationId): float
    {
        if ($variationId) {
            $variation = ProductVariation::find($variationId);
            return $variation ? $variation->averageLandedCost() : 0.0;
        }

        $totals = DB::table('purchase_invoice_items')->whereNull('purchase_invoice_items.deleted_at')
            ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
            ->where('purchase_invoice_items.item_id', $productId)
            ->where('purchase_invoices.status', 'received')
            ->whereNull('purchase_invoices.deleted_at')
            ->whereNotNull('purchase_invoice_items.received_net_weight')
            ->selectRaw('SUM(purchase_invoice_items.received_net_weight * purchase_invoice_items.price + COALESCE(purchase_invoice_items.allocated_additional_cost, 0)) as total_cost')
            ->selectRaw('SUM(purchase_invoice_items.received_net_weight) as total_weight')
            ->first();

        $totalCost   = (float) ($totals->total_cost ?? 0);
        $totalWeight = (float) ($totals->total_weight ?? 0);

        $product = Product::find($productId);
        if ($product && (float) $product->opening_weight > 0 && (float) $product->opening_rate > 0) {
            $totalCost   += (float) $product->opening_weight * (float) $product->opening_rate;
            $totalWeight += (float) $product->opening_weight;
        }

        return $totalWeight > 0 ? round($totalCost / $totalWeight, 4) : 0.0;
    }

    /**
     * Re-cost sales of the given products (null = every product).
     * Must run inside the caller's DB transaction where one exists.
     *
     * @return array list of ['invoice_no','old_cogs','new_cogs'] for invoices that changed
     */
    public static function recalculate(?array $productIds = null, bool $dryRun = false): array
    {
        $itemQuery = DB::table('sale_invoice_items')
            ->select('id', 'sale_invoice_id', 'product_id', 'variation_id', 'net_weight', 'unit_cost');
        if ($productIds !== null) {
            $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
            if (!$productIds) return [];
            $itemQuery->whereIn('product_id', $productIds);
        }
        $items = $itemQuery->get();
        if ($items->isEmpty()) return [];

        // One average per product/variation pair, not per line.
        $costCache = [];
        $invoiceIds = [];
        foreach ($items as $item) {
            $key = $item->product_id . ':' . ($item->variation_id ?? '');
            $costCache[$key] ??= self::averageCost((int) $item->product_id, $item->variation_id ? (int) $item->variation_id : null);
            $newCost = $costCache[$key];

            // No cost basis at all (no received purchase, no opening rate) —
            // leave the line as it was rather than zeroing its cost.
            if ($newCost <= 0) continue;

            if (abs($newCost - (float) $item->unit_cost) > 0.00005) {
                if (!$dryRun) {
                    DB::table('sale_invoice_items')->where('id', $item->id)->update(['unit_cost' => $newCost]);
                }
                $invoiceIds[$item->sale_invoice_id] = true;
            }
        }
        if (!$invoiceIds) return [];

        $cogsAccount      = self::account('cogs', 'Cost of Goods Sold');
        $inventoryAccount = self::account('inventory', 'Inventory / Stock in Hand');
        $hasReturns       = Schema::hasTable('sale_returns') && Schema::hasTable('sale_return_items');

        $changed = [];
        foreach (array_keys($invoiceIds) as $invoiceId) {
            $invoice = SaleInvoice::find($invoiceId);
            if (!$invoice) continue;

            $oldCogs = (float) Voucher::where('reference', "SI-{$invoice->id}-COGS")->sum('amount');

            // Lines not touched above keep their stored cost; changed ones use the new cost.
            $newCogs = 0.0;
            foreach (DB::table('sale_invoice_items')->where('sale_invoice_id', $invoice->id)->get() as $line) {
                $key  = $line->product_id . ':' . ($line->variation_id ?? '');
                $cost = (!$dryRun || !isset($costCache[$key]) || $costCache[$key] <= 0) ? (float) $line->unit_cost : $costCache[$key];
                $newCogs += $cost * (float) $line->net_weight;
            }
            $newCogs = round($newCogs, 2);

            $changed[] = ['invoice_no' => $invoice->invoice_no, 'old_cogs' => $oldCogs, 'new_cogs' => $newCogs];
            if ($dryRun) continue;

            DB::table('sale_invoices')->where('id', $invoice->id)->update(['updated_at' => now()]);

            if ($newCogs > 0) {
                Voucher::updateOrCreate(
                    ['reference' => "SI-{$invoice->id}-COGS", 'voucher_type' => 'journal'],
                    ['date' => $invoice->date, 'ac_dr_sid' => $cogsAccount->id, 'ac_cr_sid' => $inventoryAccount->id,
                     'amount' => $newCogs, 'remarks' => "Sale Invoice #{$invoice->invoice_no} — cost of goods sold (re-costed at average purchase cost)"]
                );
            }

            // Sale Returns reverse COGS at the invoice's per-kg booked cost —
            // re-price them so they still undo exactly what the sale booked.
            if ($hasReturns) {
                $totalWeight = (float) $invoice->total_weight;
                $perKg = $totalWeight > 0 ? round($newCogs / $totalWeight, 4) : 0;

                $returnIds = DB::table('sale_returns')->where('sale_invoice_id', $invoice->id)
                    ->whereNull('deleted_at')->pluck('id');
                foreach ($returnIds as $returnId) {
                    $retTotal = 0.0;
                    foreach (DB::table('sale_return_items')->where('sale_return_id', $returnId)->get() as $ri) {
                        $cogsAmount = round((float) $ri->net_weight * $perKg, 2);
                        DB::table('sale_return_items')->where('id', $ri->id)
                            ->update(['unit_cost' => $perKg, 'cogs_amount' => $cogsAmount]);
                        $retTotal += $cogsAmount;
                    }
                    DB::table('sale_returns')->where('id', $returnId)->update(['total_cogs' => round($retTotal, 2)]);
                    Voucher::where('reference', "SR-{$returnId}-COGS")->update(['amount' => round($retTotal, 2)]);
                }
            }
        }

        Log::info('[COGS] Sales re-costed at average purchase cost', [
            'products' => $productIds ?? 'all', 'invoices' => count($changed), 'dry_run' => $dryRun,
        ]);

        return $changed;
    }

    private static function account(string $configKey, string $label): ChartOfAccounts
    {
        $code = config("sale_accounts.{$configKey}");
        $account = $code ? ChartOfAccounts::where('account_code', $code)->first() : null;
        $account ??= ChartOfAccounts::where('account_type', $configKey)->first();
        if (!$account) {
            throw new \Exception("{$label} account not found (config sale_accounts.{$configKey}).");
        }
        return $account;
    }
}