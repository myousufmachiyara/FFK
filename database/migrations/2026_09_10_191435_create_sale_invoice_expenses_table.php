<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_invoice_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_invoice_id')->constrained('sale_invoices')->cascadeOnDelete();

            $table->string('expense_type', 30); // local_cartage | packaging | plastic_bags | bardana | misc | tulai | others
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2)->default(0);

            // Sale has no Vendor concept — every expense is paid by the
            // Company (FFK) to a chosen Vendor-type payee account (e.g. a
            // transporter like "Suzuki wala"). Required, not nullable.
            $table->unsignedBigInteger('payee_account_id');

            $table->timestamps();

            $table->foreign('payee_account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_invoice_expenses');
    }
};