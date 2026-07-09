<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            // Stored digits-only (7 for NTN, 13 for CNIC) - display formatting
            // (e.g. "1234567-8", "12345-1234567-1") happens in the model/UI.
            $table->string('ntn', 7)->nullable()->unique();
            $table->string('cnic', 13)->nullable()->unique();
            $table->text('address')->nullable();
            $table->string('customer_type')->default('walk_in'); // walk_in | b2b
            $table->string('atl_status')->default('unknown'); // active | inactive | unknown
            $table->timestamp('atl_checked_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('phone');
            $table->index('customer_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
