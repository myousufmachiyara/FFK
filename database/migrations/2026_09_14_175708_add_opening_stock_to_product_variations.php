<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variations', 'opening_stock')) {
                // A one-time starting balance for stock that existed before
                // this system started tracking it — kept permanently
                // separate from 'stock_quantity' (the purely transactional
                // figure driven by Purchase/Sale). Available stock is
                // always opening_stock + stock_quantity, computed at
                // display/check time — never merged into stock_quantity
                // itself, so RecalculateStock's transaction-only rebuild
                // never double-counts it.
                $table->decimal('opening_stock', 15, 3)->default(0)->after('stock_quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (Schema::hasColumn('product_variations', 'opening_stock')) {
                $table->dropColumn('opening_stock');
            }
        });
    }
};