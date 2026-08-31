<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_invoice_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_invoice_id')->constrained('commission_invoices')->cascadeOnDelete();

            // packing | local_cartage | misc
            $table->string('expense_type', 30);
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_invoice_expenses');
    }
};
