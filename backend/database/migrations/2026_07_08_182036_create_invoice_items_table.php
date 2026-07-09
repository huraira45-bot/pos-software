<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            // The original line this credit-invoice line reverses (for partial returns / audit).
            $table->foreignId('ref_invoice_item_id')->nullable()->constrained('invoice_items')->restrictOnDelete();

            // Denormalized at time of sale so historical receipts/FBR payloads never
            // change even if the product catalog changes later.
            $table->string('item_code');
            $table->string('item_name');
            $table->string('pct_code');

            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price_excl_tax', 14, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('sale_value', 14, 2);
            $table->decimal('tax_charged', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('further_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->unsignedTinyInteger('invoice_type')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
