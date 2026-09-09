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
            $table->decimal('points_redeemed', 10, 2)->default(0.00)->after('online_received');
            $table->decimal('points_discount_amount', 10, 2)->default(0.00)->after('points_redeemed');
            $table->decimal('points_earned', 10, 2)->default(0.00)->after('points_discount_amount');
            $table->decimal('wallet_received', 10, 2)->default(0.00)->after('points_earned');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_bills', function (Blueprint $table) {
            $table->dropColumn([
                'points_redeemed',
                'points_discount_amount',
                'points_earned',
                'wallet_received',
            ]);
        });
    }
};
