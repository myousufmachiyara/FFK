<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variations', 'opening_rate')) {
                $table->decimal('opening_rate', 15, 4)->default(0)->after('opening_weight');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'opening_rate')) {
                $table->decimal('opening_rate', 15, 4)->default(0)->after('opening_weight');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            if (Schema::hasColumn('product_variations', 'opening_rate')) {
                $table->dropColumn('opening_rate');
            }
        });
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'opening_rate')) {
                $table->dropColumn('opening_rate');
            }
        });
    }
};