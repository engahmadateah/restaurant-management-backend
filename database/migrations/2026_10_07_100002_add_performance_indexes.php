<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_at', 'orders_created_at_index');
            $table->index(['status', 'created_at'], 'orders_status_created_at_index');
            $table->index('payment_status', 'orders_payment_status_index');
            $table->index(['order_type', 'delivery_status'], 'orders_type_delivery_status_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index('expense_date', 'expenses_expense_date_index');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['user_id', 'clock_in'], 'shifts_user_clock_in_index');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->index(['order_id', 'status'], 'refunds_order_status_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['order_id', 'status'], 'payments_order_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_created_at_index');
            $table->dropIndex('orders_status_created_at_index');
            $table->dropIndex('orders_payment_status_index');
            $table->dropIndex('orders_type_delivery_status_index');
        });

        Schema::table('expenses', fn (Blueprint $table) => $table->dropIndex('expenses_expense_date_index'));
        Schema::table('shifts', fn (Blueprint $table) => $table->dropIndex('shifts_user_clock_in_index'));
        Schema::table('refunds', fn (Blueprint $table) => $table->dropIndex('refunds_order_status_index'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_order_status_index'));
    }
};
