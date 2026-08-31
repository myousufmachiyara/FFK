<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_invoice_id')->constrained('sale_invoices')->cascadeOnDelete();

            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->nullOnDelete();

            $table->decimal('sale_price', 15, 2);
            $table->decimal('quantity', 15, 2);
            $table->decimal('discount', 5, 2)->default(0);   // percentage, per line
            $table->decimal('total', 15, 2)->default(0);

            // Snapshot of the unit cost used for COGS at time of sale — needed
            // for traceable, auditable Cost of Goods Sold reporting.
            $table->decimal('unit_cost', 15, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_invoice_items');
    }
};
