<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            // Packing container type (Bag, Drum, etc) — separate from the
            // old 'unit' column, which is left untouched for backward
            // compatibility but no longer used by the create/edit forms.
            $table->unsignedBigInteger('packing_unit_id')->nullable()->after('unit');

            $table->decimal('wt_per_packing', 15, 3)->nullable()->after('packing_unit_id'); // weight per bag/drum
            // 'quantity' (existing column) = number of packing units (bags)
            $table->decimal('gross_weight', 15, 3)->nullable()->after('quantity');           // wt_per_packing * quantity
            $table->decimal('net_weight', 15, 3)->nullable()->after('gross_weight');          // editable, defaults to gross_weight

            $table->decimal('rate_per_40kg', 15, 2)->nullable()->after('net_weight');         // user-entered rate/maund
            // 'price' (existing column) is REPURPOSED to mean rate per KG,
            // auto-computed as rate_per_40kg / kg_per_maund. Kept as
            // 'price' rather than renamed so landedUnitCost() and the
            // Sale module's resolveUnitCost() keep working unchanged.

            $table->decimal('amount', 15, 2)->default(0)->after('rate_per_40kg');             // price * net_weight, the line total

            // Weight-based receiving/shortage (replaces bag-count-based
            // dispatched_quantity/received_quantity/short_quantity for
            // costing purposes — those columns are kept for informational
            // bag-count reconciliation only, see received_packing_qty below).
            $table->decimal('received_packing_qty', 15, 2)->nullable()->after('received_quantity');
            $table->decimal('received_net_weight', 15, 3)->nullable()->after('received_packing_qty');
            $table->decimal('short_weight', 15, 3)->default(0)->after('received_net_weight');

            $table->foreign('packing_unit_id')->references('id')->on('measurement_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['packing_unit_id']);
            $table->dropColumn([
                'packing_unit_id', 'wt_per_packing', 'gross_weight', 'net_weight',
                'rate_per_40kg', 'amount', 'received_packing_qty', 'received_net_weight', 'short_weight',
            ]);
        });
    }
};
