<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_invoice_id')->constrained('commission_invoices')->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->nullOnDelete();
            $table->unsignedBigInteger('unit_id')->nullable();

            $table->decimal('quantity', 15, 2);
            $table->decimal('weight', 15, 2)->default(0);

            $table->decimal('purchase_price', 15, 2);
            $table->decimal('sale_price', 15, 2);
            $table->decimal('commission_percentage', 5, 2)->default(0);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->decimal('purchase_total', 15, 2)->default(0);
            $table->decimal('sale_total', 15, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_invoice_items');
    }
};
