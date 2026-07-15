<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * usin_counters was one row per terminal (PK: terminal_id). Now needs one row
 * per (terminal_id, usin_type) so each terminal can run independent gapless
 * sequences for SIR- and SS_-prefixed USINs (see UsinGenerator). Rebuilding
 * the table (create new, copy, swap) rather than altering the existing PK in
 * place keeps this portable across Postgres (tests/production) and SQLite
 * (local dev) - primary key changes are exactly the ALTER TABLE operation
 * SQLite handles poorly even with doctrine/dbal installed.
 *
 * Existing counters predate the prefix scheme; each terminal's running count
 * is preserved under usin_type='SIR' (arbitrary but non-destructive, and
 * immediately adjustable via the USIN counter settings screen) and a fresh
 * 'SS' row is added per terminal starting at 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usin_counters_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->string('usin_type', 10);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
            $table->unique(['terminal_id', 'usin_type']);
        });

        $now = now();
        foreach (DB::table('usin_counters')->get() as $row) {
            DB::table('usin_counters_new')->insert([
                'terminal_id' => $row->terminal_id,
                'usin_type' => 'SIR',
                'last_value' => $row->last_value,
                'created_at' => $row->created_at,
                'updated_at' => $now,
            ]);
            DB::table('usin_counters_new')->insert([
                'terminal_id' => $row->terminal_id,
                'usin_type' => 'SS',
                'last_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::drop('usin_counters');
        Schema::rename('usin_counters_new', 'usin_counters');
    }

    public function down(): void
    {
        Schema::create('usin_counters_old', function (Blueprint $table) {
            $table->foreignId('terminal_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });

        foreach (DB::table('usin_counters')->where('usin_type', 'SIR')->get() as $row) {
            DB::table('usin_counters_old')->insert([
                'terminal_id' => $row->terminal_id,
                'last_value' => $row->last_value,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('usin_counters');
        Schema::rename('usin_counters_old', 'usin_counters');
    }
};
