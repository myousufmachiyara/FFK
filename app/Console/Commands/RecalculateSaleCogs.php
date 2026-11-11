<?php

namespace App\Console\Commands;

use App\Services\SaleCostRecalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateSaleCogs extends Command
{
    protected $signature = 'sales:recalculate-cogs {--product= : Only re-cost sales of one product ID} {--dry-run : Show what would change without saving}';

    protected $description = 'Re-cost every Sale Invoice line at the current weighted-average purchase cost and update its COGS voucher (and any Sale Return COGS reversals). Fixes sales saved with a stale cost — e.g. entered before all purchases of that item were received.';

    public function handle()
    {
        $dryRun    = (bool) $this->option('dry-run');
        $productId = $this->option('product');

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Re-costing sales at average purchase cost...');

        $changed = DB::transaction(fn () => SaleCostRecalculator::recalculate(
            $productId ? [(int) $productId] : null,
            $dryRun
        ));

        if (!$changed) {
            $this->info('All sale costs are already up to date — nothing changed.');
            return 0;
        }

        $this->table(
            ['Invoice', 'Old COGS', 'New COGS', 'Difference'],
            array_map(fn ($r) => [
                $r['invoice_no'],
                number_format($r['old_cogs'], 2),
                number_format($r['new_cogs'], 2),
                number_format($r['new_cogs'] - $r['old_cogs'], 2),
            ], $changed)
        );

        $this->info(($dryRun ? 'Would update ' : 'Updated ') . count($changed) . ' invoice(s).'
            . ($dryRun ? ' Run again without --dry-run to save.' : ''));

        return 0;
    }
}