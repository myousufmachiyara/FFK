<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_expenses', function (Blueprint $table) {
            // 'vendor' = this expense increases what we owe the Vendor.
            // 'company' = FFK owes a SPECIFIC account instead (e.g. a named
            // transporter) — Vendor is untouched.
            $table->string('paid_by', 20)->default('vendor')->after('amount');
            $table->unsignedBigInteger('payee_account_id')->nullable()->after('paid_by');

            $table->foreign('payee_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_expenses', function (Blueprint $table) {
            $table->dropForeign(['payee_account_id']);
            $table->dropColumn(['paid_by', 'payee_account_id']);
        });
    }
};