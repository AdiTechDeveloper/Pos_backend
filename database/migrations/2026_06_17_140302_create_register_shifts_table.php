<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('register_shifts', function (Blueprint $table) {
        // Add these only if they don't already exist
        if (!Schema::hasColumn('register_shifts', 'closing_balance')) {
            $table->decimal('closing_balance', 10, 2)->nullable();
        }
        if (!Schema::hasColumn('register_shifts', 'expected_closing_balance')) {
            $table->decimal('expected_closing_balance', 10, 2)->nullable();
        }
        if (!Schema::hasColumn('register_shifts', 'discrepancy')) {
            $table->decimal('discrepancy', 10, 2)->nullable();
        }
        if (!Schema::hasColumn('register_shifts', 'closed_at')) {
            $table->timestamp('closed_at')->nullable();
        }
        if (!Schema::hasColumn('register_shifts', 'other_expenses')) {
            $table->decimal('other_expenses', 10, 2)->default(0);
        }
        if (!Schema::hasColumn('register_shifts', 'expense_description')) {
            $table->string('expense_description')->nullable();
        }
    });
}

public function down()
{
    Schema::table('register_shifts', function (Blueprint $table) {
        $table->dropColumn([
            'closing_balance',
            'expected_closing_balance',
            'discrepancy',
            'closed_at',
            'other_expenses',
            'expense_description',
        ]);
    });
}
};
