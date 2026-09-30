<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('commission_returns', 'total_purchase_value')) {
                $table->decimal('total_purchase_value', 15, 2)->default(0)->after('total_sale_value');
            }
        });

        Schema::table('commission_return_items', function (Blueprint $table) {
            if (!Schema::hasColumn('commission_return_items', 'purchase_value')) {
                $table->decimal('purchase_value', 15, 2)->default(0)->after('sale_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('commission_returns', function (Blueprint $table) {
            if (Schema::hasColumn('commission_returns', 'total_purchase_value')) {
                $table->dropColumn('total_purchase_value');
            }
        });
        Schema::table('commission_return_items', function (Blueprint $table) {
            if (Schema::hasColumn('commission_return_items', 'purchase_value')) {
                $table->dropColumn('purchase_value');
            }
        });
    }
};