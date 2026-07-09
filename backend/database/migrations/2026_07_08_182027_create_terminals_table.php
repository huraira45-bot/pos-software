<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            // FBR POS registration number for this till - int per FBR data model.
            $table->unsignedInteger('fbr_pos_id')->unique();
            // Per-terminal override of the global fiscalization strategy; null = use config default.
            $table->string('fiscal_mode')->nullable();
            $table->string('fiscal_endpoint_override')->nullable();
            $table->text('fiscal_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminals');
    }
};
