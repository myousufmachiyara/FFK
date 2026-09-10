<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            // Optional — a Sale normally has no vendor. This exists ONLY so an
            // expense on this invoice can be routed to "Vendor" if one applies
            // (e.g. drop-ship style sale). Leave null for a normal sale.
            $table->unsignedBigInteger('vendor_id')->nullable()->after('account_id');

            // 'type' (existing column) already IS cash/credit — just adding the
            // day count for credit terms.
            $table->unsignedInteger('credit_days')->nullable()->after('type');

            $table->decimal('total_weight', 15, 3)->default(0)->after('net_amount');
            $table->decimal('total_gross_weight', 15, 3)->default(0)->after('total_weight');
            $table->decimal('total_other_expenses', 15, 2)->default(0)->after('total_gross_weight');

            $table->foreign('vendor_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropColumn(['vendor_id', 'credit_days', 'total_weight', 'total_gross_weight', 'total_other_expenses']);
        });
    }
};