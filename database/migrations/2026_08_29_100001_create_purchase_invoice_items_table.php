<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();

            // Named 'item_id' (not 'product_id') to match the original controller's usage.
            $table->foreignId('item_id')->constrained('products');
            $table->foreignId('variation_id')->nullable()->constrained('product_variations')->nullOnDelete();
            $table->unsignedBigInteger('unit')->nullable(); // FK to measurement_units.id

            $table->decimal('quantity', 15, 2);   // Ordered Quantity
            $table->decimal('price', 15, 2);

            // Receiving / shortage tracking
            $table->decimal('dispatched_quantity', 15, 2)->nullable();
            $table->decimal('received_quantity', 15, 2)->nullable();
            $table->decimal('short_quantity', 15, 2)->default(0);
            $table->text('shortage_reason')->nullable();
            $table->decimal('allocated_additional_cost', 15, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('unit')->references('id')->on('measurement_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
    }
};
