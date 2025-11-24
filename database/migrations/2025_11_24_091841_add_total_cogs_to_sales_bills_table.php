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
            $table->decimal('total_cogs', 15, 2)->default(0)->after('total_saved');
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
