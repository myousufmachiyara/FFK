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
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id(); // Auto-increment primary key
            $table->string('account_code', 20)->unique();
            $table->unsignedBigInteger('shoa_id'); // Foreign key for sub_head_of_accounts
            $table->string('name'); // Name of the account
            $table->string('trn')->nullable(); // Name of the account
            $table->string('account_type')->nullable();
            $table->decimal('receivables', 15, 2)->default(0);
            $table->decimal('payables', 15, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->date('opening_date'); // Opening date for the account
            $table->string('remarks')->nullable(); // Optional remarks
            $table->string('address')->nullable(); // Optional address
            $table->string('contact_no')->nullable(); // Optional phone number
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps(); // Includes created_at and updated_at
            $table->softDeletes(); // Includes deleted_at for soft deletes

            // Foreign key constraint with cascade delete
            $table->foreign('shoa_id')->references('id')->on('sub_head_of_accounts')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
