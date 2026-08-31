<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->string('status')->default('success')->after('payment_date');
            $table->foreignId('updated_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable()->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['status', 'updated_by', 'remarks']);
        });
    }
};
