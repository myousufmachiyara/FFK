<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('total_weight', 15, 3)->default(0)->after('total_quantity');

            // Sum of purchase_invoice_expenses.amount for this invoice —
            // replaces bilty_charges/labor_charges/other_charges as the
            // "additional receiving cost" total. Those 3 columns are left
            // in the schema (unused going forward) rather than dropped,
            // so nothing breaks if old code/reports still reference them.
            $table->decimal('total_other_expenses', 15, 2)->default(0)->after('other_charges');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['total_weight', 'total_other_expenses']);
        });
    }
};
