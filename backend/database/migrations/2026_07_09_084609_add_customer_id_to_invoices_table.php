<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            // Audit trail for the non-ATL B2B further-tax decision - why it was
            // applied, confirmed, or waived, and by whom (cashier_id already exists).
            $table->boolean('non_atl_confirmed')->default(false)->after('further_tax');
            $table->boolean('further_tax_waived')->default(false)->after('non_atl_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['non_atl_confirmed', 'further_tax_waived']);
        });
    }
};
