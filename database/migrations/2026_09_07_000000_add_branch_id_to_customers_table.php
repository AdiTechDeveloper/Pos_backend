<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE customers c
            SET branch_id = (
                SELECT MIN(sb.branch_id)
                FROM sales_bills sb
                WHERE sb.customer_id = c.id
                  AND sb.branch_id IS NOT NULL
            )
            WHERE c.branch_id IS NULL
        SQL);

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('mobile');
            $table->unique(['branch_id', 'mobile']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'mobile']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
            $table->unique('mobile');
        });
    }
};
