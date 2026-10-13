<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_drawers', function (Blueprint $table) {
            $table->decimal('cash_expenses', 12, 2)->nullable()->after('cash_refunds');
            $table->decimal('cash_withdrawals', 12, 2)->nullable()->after('cash_expenses');
            $table->decimal('cash_deposits', 12, 2)->nullable()->after('cash_withdrawals');
        });
    }

    public function down(): void
    {
        Schema::table('cash_drawers', function (Blueprint $table) {
            $table->dropColumn(['cash_expenses', 'cash_withdrawals', 'cash_deposits']);
        });
    }
};
