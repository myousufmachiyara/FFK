<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('commission_returns')) {
            Schema::create('commission_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commission_invoice_id')->constrained('commission_invoices')->cascadeOnDelete();
                $table->string('return_no')->unique();
                $table->date('return_date');
                $table->foreignId('vendor_id')->constrained('chart_of_accounts');
                $table->foreignId('customer_id')->constrained('chart_of_accounts');
                $table->text('reason')->nullable();
                $table->decimal('total_weight', 15, 3)->default(0);
                $table->decimal('total_sale_value', 15, 2)->default(0);        // goods value reversed
                $table->decimal('total_vendor_commission', 15, 2)->default(0); // vendor commission reversed
                $table->decimal('total_customer_commission', 15, 2)->default(0); // customer commission reversed
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('commission_return_items')) {
            Schema::create('commission_return_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commission_return_id')->constrained('commission_returns')->cascadeOnDelete();
                $table->foreignId('commission_invoice_item_id')->constrained('commission_invoice_items');
                $table->foreignId('product_id')->constrained('products');
                $table->foreignId('variation_id')->nullable()->constrained('product_variations');
                $table->decimal('qty', 15, 3);
                $table->decimal('net_weight', 15, 3);
                // Proportional amounts — computed as (returned_weight / original_item_weight) * original figures.
                $table->decimal('sale_value', 15, 2)->default(0);
                $table->decimal('vendor_commission', 15, 2)->default(0);
                $table->decimal('customer_commission', 15, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_return_items');
        Schema::dropIfExists('commission_returns');
    }
};