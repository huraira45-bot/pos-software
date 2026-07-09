<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per terminal. USIN generation locks this row with SELECT ... FOR UPDATE
        // inside the same DB transaction as the invoice insert, so a rolled-back sale
        // never consumes (and thus never skips) a sequence value.
        Schema::create('usin_counters', function (Blueprint $table) {
            $table->foreignId('terminal_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usin_counters');
    }
};
