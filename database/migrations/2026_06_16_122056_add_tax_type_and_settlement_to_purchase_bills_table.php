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
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->string('tax_type')->default('exclusive')->after('bill_date');
            $table->decimal('settlement_amount', 15, 2)->nullable()->after('total_amount');
            $table->text('notes')->nullable()->after('settlement_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropColumn(['tax_type', 'settlement_amount', 'notes']);
        });
    }
};
