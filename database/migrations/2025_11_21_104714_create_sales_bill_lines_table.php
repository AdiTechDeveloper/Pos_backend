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
        Schema::create('sales_bill_lines', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sales_bill_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id');

            $table->unsignedBigInteger('inventory_id')->nullable();

            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);

            $table->decimal('cgst', 15, 2)->default(0);
            $table->decimal('sgst', 15, 2)->default(0);
            $table->decimal('igst', 15, 2)->default(0);
            $table->decimal('total_gst', 15, 2)->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_bill_lines');
    }
};
