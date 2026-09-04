<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_invoice_items', function (Blueprint $table) {
            // Weight-based costing — same pattern as Purchase.
            $table->unsignedBigInteger('packing_unit_id')->nullable()->after('unit_id');
            $table->decimal('wt_per_packing', 15, 3)->nullable()->after('packing_unit_id');
            // 'quantity' (existing) = number of packing units.
            $table->decimal('gross_weight', 15, 3)->nullable()->after('quantity');
            $table->decimal('net_weight', 15, 3)->nullable()->after('gross_weight');
            // 'weight' (existing, old flat field) is left in place, unused.

            $table->decimal('purchase_rate_per_40kg', 15, 2)->nullable()->after('net_weight');
            // 'purchase_price' (existing) is REPURPOSED to mean rate per KG,
            // computed from purchase_rate_per_40kg. 'purchase_total' stays
            // the line total, now = purchase_price(per kg) * net_weight.

            $table->decimal('sale_rate_per_40kg', 15, 2)->nullable()->after('purchase_rate_per_40kg');
            // 'sale_price' (existing) REPURPOSED same way for the sale side.
            // 'sale_total' stays the line total = sale_price(per kg) * net_weight.

            // Dual-sided commission — 'commission_percentage'/'commission_amount'
            // (existing columns) are left in place, UNUSED going forward.
            // Replaced by these two explicit, independent legs:
            $table->decimal('vendor_commission_percentage', 5, 2)->default(0)->after('sale_rate_per_40kg');
            $table->decimal('vendor_commission_amount', 15, 2)->default(0)->after('vendor_commission_percentage');
            $table->decimal('customer_commission_percentage', 5, 2)->default(0)->after('vendor_commission_amount');
            $table->decimal('customer_commission_amount', 15, 2)->default(0)->after('customer_commission_percentage');

            $table->foreign('packing_unit_id')->references('id')->on('measurement_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['packing_unit_id']);
            $table->dropColumn([
                'packing_unit_id', 'wt_per_packing', 'gross_weight', 'net_weight',
                'purchase_rate_per_40kg', 'sale_rate_per_40kg',
                'vendor_commission_percentage', 'vendor_commission_amount',
                'customer_commission_percentage', 'customer_commission_amount',
            ]);
        });
    }
};
