<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();

            // Our Unique Sequential Invoice Number - strictly sequential per terminal,
            // assigned atomically via usin_counters (see UsinGenerator). Never reused/skipped.
            $table->unsignedBigInteger('usin');

            // FBR InvoiceType: 1=New, 2=Debit, 3=Credit
            $table->unsignedTinyInteger('invoice_type')->default(1);
            // Self-reference to the original invoice being returned/cancelled (RefUSIN source).
            $table->foreignId('ref_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();

            $table->string('fbr_invoice_number')->nullable()->unique();

            $table->string('buyer_ntn')->nullable();
            $table->string('buyer_cnic')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_phone')->nullable();

            $table->decimal('total_sale_value', 14, 2);
            $table->decimal('total_tax_charged', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('further_tax', 14, 2)->default(0);
            $table->decimal('total_bill_amount', 14, 2);

            // FBR PaymentMode: 1=Cash,2=Card,3=Gift Voucher,4=Loyalty Card,5=Mixed,6=Cheque
            $table->unsignedTinyInteger('payment_mode');
            // Present when payment_mode=5 (Mixed): [{"mode":1,"amount":"500.00"}, ...]
            $table->json('payment_breakdown')->nullable();

            // pending -> synced | failed_permanent (failed_permanent is terminal only after
            // FISCAL_MAX_RETRY_ATTEMPTS is exhausted; ops must intervene, invoice itself never mutates).
            $table->string('fiscal_status')->default('pending');
            // True if the receipt was printed before an FBR fiscal number was obtained
            // (offline sale) - drives the "FBR sync pending" receipt annotation.
            $table->boolean('printed_offline_pending')->default(false);
            $table->timestamp('synced_at')->nullable();

            $table->timestamp('sold_at');
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['terminal_id', 'usin']);
            $table->index('fiscal_status');
            $table->index(['invoice_type', 'ref_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
