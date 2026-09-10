<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('payment_terms', 10)->default('cash')->after('remarks'); // cash | credit
            $table->unsignedInteger('credit_days')->nullable()->after('payment_terms');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_terms', 'credit_days']);
        });
    }
};