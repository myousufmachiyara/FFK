<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            // 'type' (existing column) already IS cash/credit — just adding the
            // day count for credit terms.
            $table->unsignedInteger('credit_days')->nullable()->after('type');

            $table->decimal('total_weight', 15, 3)->default(0)->after('net_amount');
            $table->decimal('total_gross_weight', 15, 3)->default(0)->after('total_weight');
            $table->decimal('total_other_expenses', 15, 2)->default(0)->after('total_gross_weight');
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropColumn(['credit_days', 'total_weight', 'total_gross_weight', 'total_other_expenses']);
        });
    }
};