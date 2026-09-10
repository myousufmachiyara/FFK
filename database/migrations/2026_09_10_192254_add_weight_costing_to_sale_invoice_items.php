<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoice_items', function (Blueprint $table) {
            $table->unsignedBigInteger('packing_unit_id')->nullable()->after('variation_id');
            $table->decimal('wt_per_packing', 15, 3)->nullable()->after('packing_unit_id'); // weight per bag/carton
            // 'quantity' (existing) is REPURPOSED to mean number of packing units, not kg.
            $table->decimal('gross_weight', 15, 3)->nullable()->after('quantity');
            $table->decimal('net_weight', 15, 3)->nullable()->after('gross_weight');         // editable, defaults to gross

            $table->decimal('rate_per_40kg', 15, 2)->nullable()->after('net_weight');
            // 'sale_price' (existing) is REPURPOSED to mean rate per KG, computed from
            // rate_per_40kg. 'total' (existing) stays the line total, now =
            // sale_price(per kg) * net_weight (with the existing 'discount' % still
            // applying to the rate before multiplying).
            // 'unit_cost' (existing) is unchanged in meaning (cost per kg) — only what
            // it gets multiplied by changes (net_weight instead of quantity), see
            // SaleInvoiceController.

            $table->foreign('packing_unit_id')->references('id')->on('measurement_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['packing_unit_id']);
            $table->dropColumn(['packing_unit_id', 'wt_per_packing', 'gross_weight', 'net_weight', 'rate_per_40kg']);
        });
    }
};