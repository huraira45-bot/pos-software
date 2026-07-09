<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();

            // Idempotency key checked against FBR/local state before every (re)send, so a
            // "timeout after the FBR actually accepted it" never results in a double-post.
            $table->string('idempotency_key')->unique();

            // pending -> processing -> success | failed_permanent
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            // Claimed by a worker process to prevent two workers double-posting the same
            // invoice if a queue job is ever retried concurrently with itself.
            $table->string('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_outbox');
    }
};
