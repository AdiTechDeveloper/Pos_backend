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
        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->onDelete('cascade');
            $table->foreignId('sales_bill_line_id')->constrained('sales_bill_lines');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('inventory_id')->constrained('inventories'); // Exact batch pointer

            $table->decimal('qty', 12, 2);
            $table->decimal('rate', 15, 2);
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('amount', 15, 2);

            $table->decimal('cgst', 15, 2)->default(0.00);
            $table->decimal('sgst', 15, 2)->default(0.00);
            $table->decimal('igst', 15, 2)->default(0.00);
            $table->decimal('total_gst', 15, 2)->default(0.00);
            $table->decimal('cogs_recovered', 15, 2)->default(0.00);

            $table->boolean('is_damaged')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_return_lines');
    }
};
