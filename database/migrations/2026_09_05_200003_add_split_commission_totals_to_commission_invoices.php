<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_invoices', function (Blueprint $table) {
            // 'total_commission_amount' (existing) is kept and now means
            // the COMBINED total (vendor + customer) for quick display.
            $table->decimal('total_vendor_commission_amount', 15, 2)->default(0)->after('total_commission_amount');
            $table->decimal('total_customer_commission_amount', 15, 2)->default(0)->after('total_vendor_commission_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commission_invoices', function (Blueprint $table) {
            $table->dropColumn(['total_vendor_commission_amount', 'total_customer_commission_amount']);
        });
    }
};
