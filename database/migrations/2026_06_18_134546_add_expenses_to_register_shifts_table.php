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
        Schema::table('register_shifts', function (Blueprint $table) {
            //
              $table->decimal('other_expenses', 10, 2)->default(0)->after('expected_closing_balance');
        $table->string('expense_description')->nullable()->after('other_expenses');
    });
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('register_shifts', function (Blueprint $table) {
            //
        });
    }
};
