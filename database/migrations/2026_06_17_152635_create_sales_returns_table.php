<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('sales_bill_id')->constrained('sales_bills');
            $table->string('return_no')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers');

            $table->decimal('subtotal', 15, 2)->default(0.00);
            $table->decimal('total_gst', 15, 2)->default(0.00);
            $table->decimal('total_refund_amount', 15, 2)->default(0.00);
            $table->decimal('total_cogs_recovered', 15, 2)->default(0.00);

            $table->enum('refund_type', ['cash', 'online', 'store_credit', 'credit_note']);
            $table->foreignId('processed_by')->constrained('users'); // The manager processing it
            $table->string('last_idempotency_key_return')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_returns');
    }
};
