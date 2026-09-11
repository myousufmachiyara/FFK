<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variations', 'stock_weight')) {
                // 'stock_quantity' (existing) is now the authoritative
                // tracked unit — bags/packing units. 'stock_weight' is
                // the parallel net-weight (kg) figure, maintained in
                // lockstep alongside it, purely for display ("X bags,
                // Y kg total").
                $table->decimal('stock_weight', 15, 3)->default(0)->after('stock_quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (Schema::hasColumn('product_variations', 'stock_weight')) {
                $table->dropColumn('stock_weight');
            }
        });
    }
};