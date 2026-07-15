<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * usin was a plain unsignedBigInteger; it now stores the full SIR-/SS_-prefixed
 * value (e.g. "SIR-1056", "SS_1034") that's also what gets sent to FBR/PRA's
 * USIN field, so it needs to be a string. usin_type records which counter
 * ('SIR'/'SS') produced it, kept as its own column (rather than parsed back
 * out of usin) so returns can cleanly inherit the original invoice's type and
 * reports can filter/group by it directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('usin', 50)->change();
            $table->string('usin_type', 10)->nullable()->after('usin');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('usin_type');
            $table->unsignedBigInteger('usin')->change();
        });
    }
};
