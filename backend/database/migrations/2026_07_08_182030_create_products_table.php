<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_code')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->default('pcs');
            // Pakistan Customs Tariff code - mandatory on every catalog item for FBR item payloads.
            $table->string('pct_code');
            // Sales tax rate as a percentage, e.g. 18.00 for 18%.
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('price_excl_tax', 14, 2);
            $table->decimal('cost_price', 14, 2)->nullable();
            $table->boolean('track_stock')->default(true);
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
