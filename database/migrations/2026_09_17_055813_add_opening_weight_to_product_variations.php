<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variations', 'opening_weight')) {
                // Kg equivalent of opening_stock (bags) — same "fixed,
                // pre-system balance, never touched by stock:recalculate"
                // treatment as opening_stock/stock_weight.
                $table->decimal('opening_weight', 15, 3)->default(0)->after('opening_stock');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (Schema::hasColumn('product_variations', 'opening_weight')) {
                $table->dropColumn('opening_weight');
            }
        });
    }
};