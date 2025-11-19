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
        Schema::create('purchase_bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('bill_no');
            $table->date('bill_date');
            $table->decimal('taxable_value', 14, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('cess_amount', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->boolean('received')->default(false);

            $table->timestamps();


            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_bill_id')->index();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('gst_rate_id')->nullable()->index();

            $table->decimal('qty', 14, 3)->default(0);
            $table->decimal('free_qty', 14, 3)->default(0);

            $table->decimal('purchase_rate', 14, 2)->default(0);

            $table->enum('discount_type', ['percent', 'amount'])->nullable();
            $table->decimal('discount', 14, 2)->default(0);

            $table->string('hsn_code')->nullable();

            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();

            $table->decimal('taxable_value', 14, 2)->default(0);
            $table->decimal('cgst', 14, 2)->default(0);
            $table->decimal('sgst', 14, 2)->default(0);
            $table->decimal('igst', 14, 2)->default(0);

            $table->timestamps();

            $table->foreign('purchase_bill_id')->references('id')->on('purchase_bills')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('gst_rate_id')->references('id')->on('gst_rates')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_bills_and_lines');
    }
};
