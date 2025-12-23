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
        Schema::create('stock_expiry_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_line_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('expiry_date');
            $table->integer('days_left');
            $table->enum('severity', ['warning', 'danger', 'expired']);
            $table->date('alert_date');
            $table->timestamps();

            $table->unique(['purchase_line_id', 'alert_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_expiry_alerts');
    }
};
