<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_outbox_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_outbox_id')->constrained('fiscal_outbox')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('adapter'); // fbr_cloud, fbr_sandbox, local_sdc, mock
            $table->json('request_payload'); // Authorization header redacted before storage.
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at');

            $table->index(['fiscal_outbox_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_outbox_attempts');
    }
};
