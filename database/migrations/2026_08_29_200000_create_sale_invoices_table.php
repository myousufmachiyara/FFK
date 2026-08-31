<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 20)->unique();
            $table->date('date');

            $table->foreignId('account_id')->constrained('chart_of_accounts'); // Customer

            $table->enum('type', ['cash', 'credit'])->default('cash');
            $table->text('remarks')->nullable();

            $table->decimal('discount', 15, 2)->default(0);       // flat, invoice-level
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->decimal('amount_received', 15, 2)->default(0); // cumulative across all receipts

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_invoices');
    }
};
