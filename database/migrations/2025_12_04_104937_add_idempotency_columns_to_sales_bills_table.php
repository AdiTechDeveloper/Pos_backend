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
            Schema::table('sales_bills', function (Blueprint $table) {
                // Rename old key
                if (Schema::hasColumn('sales_bills', 'last_idempotency_key')) {
                    $table->renameColumn('last_idempotency_key', 'last_idempotency_key_store');
                }

                // Add payment idempotency key
                $table->string('last_idempotency_key_payment')->nullable()->after('last_idempotency_key_store');
            });
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
