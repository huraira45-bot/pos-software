<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'product_variant_id'], 'stock_levels_branch_product_variant_unique');
        });

        // Postgres treats each NULL as distinct in a normal unique index, which would let
        // duplicate rows pile up for non-variant products (product_variant_id always NULL).
        // A partial index closes that gap for the NULL case specifically.
        DB::statement(
            'CREATE UNIQUE INDEX stock_levels_branch_product_null_variant_unique
             ON stock_levels (branch_id, product_id)
             WHERE product_variant_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
