<?php

namespace App\Console\Commands;

use App\Models\ProductVariation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateStock extends Command
{
    protected $signature = 'stock:recalculate {--variation= : Only recalculate one variation ID} {--dry-run : Show what would change without saving}';

    protected $description = 'Recalculate stock_quantity (bags) and stock_weight (kg) for every product variation from actual Purchase/Sale history. Use after the stock-tracking-unit fix to correct figures recorded under the old (weight-based) logic.';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $onlyVariationId = $this->option('variation');

        $query = ProductVariation::query();
        if ($onlyVariationId) {
            $query->where('id', $onlyVariationId);
        }

        $variations = $query->get();

        if ($variations->isEmpty()) {
            $this->error('No variations found.');
            return 1;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Recalculating stock for {$variations->count()} variation(s)...");
        $this->newLine();

        $headers = ['ID', 'SKU', 'Old Qty (bags)', 'New Qty (bags)', 'Old Weight (kg)', 'New Weight (kg)', 'Changed?'];
        $rows = [];

        foreach ($variations as $variation) {
            // FIX: purchased-side now falls back to 'quantity' (the
            // dispatched amount) when 'received_packing_qty' is null —
            // any invoice Received under the old pre-fix logic never had
            // that field populated at all, which was silently zeroing out
            // its contribution to stock.
            $purchasedQty = (float) DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->where('purchase_invoice_items.variation_id', $variation->id)
                ->where('purchase_invoices.status', 'received')
                ->whereNull('purchase_invoices.deleted_at')
                ->sum(DB::raw('COALESCE(purchase_invoice_items.received_packing_qty, purchase_invoice_items.quantity)'));

            $purchasedWeight = (float) DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->where('purchase_invoice_items.variation_id', $variation->id)
                ->where('purchase_invoices.status', 'received')
                ->whereNull('purchase_invoices.deleted_at')
                ->sum(DB::raw('COALESCE(purchase_invoice_items.received_net_weight, purchase_invoice_items.net_weight)'));

            // FIX: these previously summed ALL sale_invoice_items rows for
            // this variation with no join at all — including rows
            // orphaned by a Sale Invoice that was deleted (Sale is hard-
            // deleted; any row left over from before proper item cleanup
            // existed stays in the table forever unless explicitly
            // excluded). Joining to sale_invoices means only items whose
            // parent invoice still actually exists get counted.
            $soldQty = (float) DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->where('sale_invoice_items.variation_id', $variation->id)
                ->sum('sale_invoice_items.quantity');

            $soldWeight = (float) DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->where('sale_invoice_items.variation_id', $variation->id)
                ->sum('sale_invoice_items.net_weight');

            $newQty = round($purchasedQty - $soldQty, 2);
            $newWeight = round($purchasedWeight - $soldWeight, 3);

            $oldQty = (float) $variation->stock_quantity;
            $oldWeight = (float) $variation->stock_weight;

            $changed = (abs($oldQty - $newQty) > 0.001) || (abs($oldWeight - $newWeight) > 0.001);

            $rows[] = [
                $variation->id,
                $variation->sku,
                number_format($oldQty, 2),
                number_format($newQty, 2),
                number_format($oldWeight, 3),
                number_format($newWeight, 3),
                $changed ? 'YES' : '',
            ];

            if (!$dryRun && $changed) {
                $variation->update([
                    'stock_quantity' => $newQty,
                    'stock_weight'   => $newWeight,
                ]);
            }
        }

        $this->table($headers, $rows);

        $changedCount = collect($rows)->filter(fn ($r) => $r[6] === 'YES')->count();

        if ($dryRun) {
            $this->newLine();
            $this->warn("{$changedCount} variation(s) would change. Run without --dry-run to apply.");
        } else {
            $this->newLine();
            $this->info("Done. {$changedCount} variation(s) updated.");
        }

        return 0;
    }
}