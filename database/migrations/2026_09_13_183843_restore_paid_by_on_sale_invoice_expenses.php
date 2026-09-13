<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoice_expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_invoice_expenses', 'paid_by')) {
                // 'vendor' = only valid if this Sale Invoice has a
                // vendor_id set — increases what we owe that vendor.
                // 'company' = FFK owes the selected payee_account_id
                // instead. Same pattern as Purchase and Commission.
                $table->string('paid_by', 20)->default('company')->after('amount');
            }
        });

        // payee_account_id is only required when paid_by = 'company' now,
        // so it needs to allow null again (it was made NOT NULL when
        // "vendor" didn't exist as an option).
        // Requires doctrine/dbal: composer require doctrine/dbal
        Schema::table('sale_invoice_expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('payee_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoice_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('sale_invoice_expenses', 'paid_by')) {
                $table->dropColumn('paid_by');
            }
        });
    }
};