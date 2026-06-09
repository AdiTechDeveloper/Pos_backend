<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // purchase_bills mein is_lost add karo
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->boolean('is_lost')->default(0)->after('bill_date');
        });

        // purchase_lines mein missing columns add karo
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->decimal('mrp', 14, 2)->default(0)->after('purchase_rate');
            $table->decimal('selling_price', 14, 2)->default(0)->after('mrp');
            
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropColumn('is_lost');
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn(['mrp', 'selling_price']);
        });
    }
};