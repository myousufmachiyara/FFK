<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount is gone from the Sale module entirely — the per-kg rate is what
 * gets negotiated, so a separate discount field was only ever a second way
 * of saying the same thing (and a second thing to get wrong).
 *
 * The application code already ignores these columns, so if you would
 * rather keep the historical figures, you can simply skip this one
 * migration — nothing else depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('sale_invoices', 'discount')) {
                $table->dropColumn('discount');
            }
        });

        Schema::table('sale_invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('sale_invoice_items', 'discount')) {
                $table->dropColumn('discount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_invoices', 'discount')) {
                $table->decimal('discount', 15, 2)->default(0);
            }
        });

        Schema::table('sale_invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_invoice_items', 'discount')) {
                $table->decimal('discount', 5, 2)->default(0);
            }
        });
    }
};
