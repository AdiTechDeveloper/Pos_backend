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
            // Sequenced readable number (e.g., INW-2024-0001)
            $table->string('inward_no')->nullable()->unique()->after('bill_no');
            // Raw integer sequence for easy increment per branch/year
            $table->integer('inward_sequence')->nullable()->after('inward_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->dropColumn('inward_no');
            $table->dropColumn('inward_sequence');
        });
    }
};
