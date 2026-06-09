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
            $table->unsignedBigInteger('customer_id')->nullable()->after('user_id');
            $table->decimal('paid_amount', 15, 2)->default(0)->after('total_amount');
            $table->decimal('due_amount', 15, 2)->default(0)->after('paid_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_bills', function (Blueprint $table) {
            $table->dropColumn(['customer_id', 'paid_amount', 'due_amount']);
        });
    }
};
