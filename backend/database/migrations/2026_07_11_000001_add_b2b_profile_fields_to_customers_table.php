<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('name');
            $table->string('contact_person')->nullable()->after('company_name');
            $table->string('email')->nullable()->after('phone');
            $table->string('strn', 50)->nullable()->after('cnic');
            $table->text('billing_address')->nullable()->after('address');
            $table->text('shipping_address')->nullable()->after('billing_address');
            $table->unsignedSmallInteger('payment_terms_days')->default(0)->after('atl_checked_at');
            $table->decimal('credit_limit', 14, 2)->default(0)->after('payment_terms_days');
            $table->decimal('opening_balance', 14, 2)->default(0)->after('credit_limit');
            $table->string('price_level')->default('retail')->after('opening_balance');

            $table->index('company_name');
            $table->index('price_level');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['company_name']);
            $table->dropIndex(['price_level']);
            $table->dropColumn([
                'company_name',
                'contact_person',
                'email',
                'strn',
                'billing_address',
                'shipping_address',
                'payment_terms_days',
                'credit_limit',
                'opening_balance',
                'price_level',
            ]);
        });
    }
};
