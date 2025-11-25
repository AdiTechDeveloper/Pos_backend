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
        Schema::table('sales_bills', function (Blueprint $table) {
            $table->string('payment_status')->default('unpaid')
                ->comment('unpaid, partial, paid')->after('balance_return');
            $table->string('bill_status')->default('pending')
                ->comment('pending, completed, cancelled')->after('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_bills', function (Blueprint $table) {
            //
        });
    }
};
