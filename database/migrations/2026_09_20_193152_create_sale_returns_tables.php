<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sale_returns')) {
            Schema::create('sale_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_invoice_id')->constrained('sale_invoices')->cascadeOnDelete();
                $table->string('return_no')->unique();
                $table->date('return_date');
                $table->foreignId('customer_id')->constrained('chart_of_accounts');
                $table->text('reason')->nullable();
                $table->decimal('total_weight', 15, 3)->default(0);
                $table->decimal('total_amount', 15, 2)->default(0);   // revenue reversed
                $table->decimal('total_cogs', 15, 2)->default(0);      // COGS reversed
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('sale_return_items')) {
            Schema::create('sale_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
                $table->foreignId('sale_invoice_item_id')->constrained('sale_invoice_items');
                $table->foreignId('product_id')->constrained('products');
                $table->foreignId('variation_id')->nullable()->constrained('product_variations');
                $table->decimal('qty', 15, 3);           // bags returned
                $table->decimal('net_weight', 15, 3);    // kg returned
                $table->decimal('price', 15, 4);          // sale rate/kg — copied from original
                $table->decimal('amount', 15, 2);          // net_weight * price (revenue reversed)
                $table->decimal('unit_cost', 15, 4)->default(0); // COGS rate/kg — derived from original SI-{id}-COGS voucher
                $table->decimal('cogs_amount', 15, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};