<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_returns', function (Blueprint $table) {
            if (!Schema::hasColumn('commission_returns', 'return_type')) {
                // 'vendor'    = goods physically go back to the vendor
                // 'stock_in'  = goods come into FFK's own stock instead
                $table->string('return_type', 20)->default('vendor')->after('reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('commission_returns', function (Blueprint $table) {
            if (Schema::hasColumn('commission_returns', 'return_type')) {
                $table->dropColumn('return_type');
            }
        });
    }
};