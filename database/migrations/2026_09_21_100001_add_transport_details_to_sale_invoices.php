<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Sale is delivered the same way a Purchase arrives — by a transporter,
 * against a bilti. Those two details were only being captured on Purchase
 * and Commission; the Sale Invoice print needs them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_invoices', 'bilty_no')) {
                $table->string('bilty_no')->nullable()->after('remarks');
            }
            if (!Schema::hasColumn('sale_invoices', 'transport_name')) {
                $table->string('transport_name')->nullable()->after('bilty_no');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_invoices', function (Blueprint $table) {
            foreach (['bilty_no', 'transport_name'] as $col) {
                if (Schema::hasColumn('sale_invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
