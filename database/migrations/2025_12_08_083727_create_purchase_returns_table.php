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
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_bill_id')->nullable();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('return_date');

            $table->decimal('total_taxable', 14, 2)->default(0);
            $table->decimal('total_gst', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('supplier_id');
            $table->index('branch_id');
            $table->index('purchase_bill_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
