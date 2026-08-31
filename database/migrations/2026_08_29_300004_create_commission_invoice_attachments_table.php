<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_invoice_id')->constrained('commission_invoices')->cascadeOnDelete();

            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('file_type', 100)->nullable();

            // pending | in_transit | delivered — which stage this evidence belongs to
            $table->string('stage', 20)->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_invoice_attachments');
    }
};
