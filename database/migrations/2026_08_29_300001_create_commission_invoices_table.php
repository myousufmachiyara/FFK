<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 20)->unique();
            $table->date('invoice_date');

            $table->foreignId('vendor_id')->constrained('chart_of_accounts');
            $table->foreignId('customer_id')->constrained('chart_of_accounts');

            $table->string('transport_name', 150)->nullable();
            $table->string('bilty_no', 100)->nullable();
            $table->string('vendor_bill_no', 100)->nullable();
            $table->string('ref_no', 100)->nullable();
            $table->text('remarks')->nullable();

            // pending -> in_transit -> delivered
            $table->string('status', 20)->default('pending');

            $table->decimal('total_quantity', 15, 2)->default(0);
            $table->decimal('total_weight', 15, 2)->default(0);
            $table->decimal('total_purchase_amount', 15, 2)->default(0);
            $table->decimal('total_sale_amount', 15, 2)->default(0);
            $table->decimal('total_commission_amount', 15, 2)->default(0);
            $table->decimal('total_other_expenses', 15, 2)->default(0);

            $table->timestamp('delivered_at')->nullable();
            $table->unsignedBigInteger('delivered_by')->nullable();
            $table->string('delivery_received_by_name', 150)->nullable();
            $table->text('delivery_remarks')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('delivered_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_invoices');
    }
};
