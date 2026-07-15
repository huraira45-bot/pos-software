<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['atl_status', 'atl_checked_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['non_atl_confirmed', 'further_tax_waived']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('atl_status')->default('unknown')->after('customer_type');
            $table->timestamp('atl_checked_at')->nullable()->after('atl_status');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('non_atl_confirmed')->default(false)->after('further_tax');
            $table->boolean('further_tax_waived')->default(false)->after('non_atl_confirmed');
        });
    }
};
