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
        Schema::create('sub_head_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hoa_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes(); // Add soft deletes column

            $table->foreign('hoa_id')->references('id')->on('head_of_accounts')->onDelete('cascade');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_head_of_accounts');
    }
};
