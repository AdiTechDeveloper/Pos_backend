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
        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('purchase_bill_line_id')->nullable();
            $table->unsignedBigInteger('product_id');

            $table->integer('qty')->default(0);
            $table->integer('free')->default(0);
            $table->decimal('rate', 14, 2)->default(0);

            $table->unsignedBigInteger('gst_rate_id')->nullable();
            $table->string('hsn_code', 64)->nullable();

            $table->decimal('taxable_value', 14, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);

            $table->decimal('line_total', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->onDelete('cascade');
            $table->index('product_id');
            $table->index('purchase_bill_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
    }
};
