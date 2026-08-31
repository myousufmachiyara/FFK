<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 20)->unique();
            $table->date('invoice_date');

            $table->foreignId('vendor_id')->constrained('chart_of_accounts');

            $table->string('bill_no', 100)->nullable();     // = "Vendor Bill Number"
            $table->string('bilty_no', 100)->nullable();
            $table->string('ref_no', 100)->nullable();
            $table->text('remarks')->nullable();

            // Workflow: pending -> in_transit -> received
            $table->string('status', 20)->default('pending');

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_quantity', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);

            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();

            // Additional receiving costs (entered at Received stage)
            $table->decimal('bilty_charges', 15, 2)->default(0);
            $table->decimal('labor_charges', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoices');
    }
};
