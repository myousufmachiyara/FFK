<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
                $table->string('return_no')->unique();
                $table->date('return_date');
                $table->foreignId('vendor_id')->constrained('chart_of_accounts');
                $table->text('reason')->nullable();
                $table->decimal('total_weight', 15, 3)->default(0);
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('purchase_return_items')) {
            Schema::create('purchase_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
                // The specific original received line this return is against —
                // this is what lets us correctly cap "how much can still be
                // returned" per item across possibly multiple return records.
                $table->foreignId('purchase_invoice_item_id')->constrained('purchase_invoice_items');
                $table->foreignId('item_id')->constrained('products'); // product
                $table->foreignId('variation_id')->nullable()->constrained('product_variations');
                $table->decimal('quantity', 15, 3);      // bags returned
                $table->decimal('net_weight', 15, 3);    // kg returned
                $table->decimal('price', 15, 4);         // rate/kg — copied from the original purchase item, not re-entered
                $table->decimal('amount', 15, 2);         // net_weight * price
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};