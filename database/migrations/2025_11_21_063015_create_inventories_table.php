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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id');


            $table->unsignedBigInteger('purchase_bill_id')->nullable();
            $table->unsignedBigInteger('purchase_line_id')->nullable();


            $table->decimal('qty', 10, 2)->default(0);
            $table->boolean('free')->default(false);


            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();


            $table->decimal('rate', 15, 4)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            $table->index(['product_id']);
            $table->index(['branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
