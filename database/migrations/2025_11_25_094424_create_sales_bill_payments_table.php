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
        Schema::create('sales_bill_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_bill_id');
            $table->string('method'); // cash, card, upi, wallet, online
            $table->decimal('amount', 10, 2);
            $table->string('transaction_id')->nullable(); // gateway txn id
            $table->string('gateway')->nullable(); // razorpay/stripe
            $table->string('status')->default('success'); // success/failed/pending
            $table->timestamps();

            $table->foreign('sales_bill_id')->references('id')->on('sales_bills')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_bill_payments');
    }
};
