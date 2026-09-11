<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_invoices', 'credit_days')) {
                $table->unsignedInteger('credit_days')->nullable()->after('type');
            }
            if (!Schema::hasColumn('sale_invoices', 'total_weight')) {
                $table->decimal('total_weight', 15, 3)->default(0)->after('net_amount');
            }
            if (!Schema::hasColumn('sale_invoices', 'total_gross_weight')) {
                $table->decimal('total_gross_weight', 15, 3)->default(0)->after('total_weight');
            }
            if (!Schema::hasColumn('sale_invoices', 'total_other_expenses')) {
                $table->decimal('total_other_expenses', 15, 2)->default(0)->after('total_gross_weight');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            foreach (['credit_days', 'total_weight', 'total_gross_weight', 'total_other_expenses'] as $col) {
                if (Schema::hasColumn('sale_invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};