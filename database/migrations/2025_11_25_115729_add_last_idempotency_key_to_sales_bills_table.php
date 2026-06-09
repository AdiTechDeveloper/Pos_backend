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
        if (!Schema::hasColumn('sales_bills', 'last_idempotency_key_payment')) {
            $table->string('last_idempotency_key_payment')->nullable()->after('last_idempotency_key_store');
        }
        // do same for any other columns added in this migration
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::table('sales_bills', function (Blueprint $table) {
        //     //
        // });
    }
};
