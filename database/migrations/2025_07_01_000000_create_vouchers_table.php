<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id(); 
            $table->string('voucher_type', 50); // flexible, can be 'payment', 'receipt', 'journal', or any future type
            $table->date('date');
            $table->unsignedBigInteger('ac_dr_sid'); 
            $table->unsignedBigInteger('ac_cr_sid'); 
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();
            $table->text('remarks')->nullable();
            $table->string('description')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ac_dr_sid')->references('id')->on('chart_of_accounts')->onDelete('cascade');
            $table->foreign('ac_cr_sid')->references('id')->on('chart_of_accounts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
