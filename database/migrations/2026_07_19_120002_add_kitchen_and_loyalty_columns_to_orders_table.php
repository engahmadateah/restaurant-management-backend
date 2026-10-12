<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // ---------- توقيتات نظام المطبخ (Kitchen Display) ----------
            // لتتبع مدة كل مرحلة (كم استغرق التحضير، كم استنى الزبون...)
            $table->timestamp('preparing_at')->nullable()->after('status');
            $table->timestamp('ready_at')->nullable()->after('preparing_at');
            $table->timestamp('completed_at')->nullable()->after('ready_at');

            // ---------- نقاط الولاء المرتبطة بهاد الطلب ----------
            $table->unsignedInteger('loyalty_points_earned')->default(0)->after('payment_status');
            $table->unsignedInteger('loyalty_points_redeemed')->default(0)->after('loyalty_points_earned');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'preparing_at', 'ready_at', 'completed_at',
                'loyalty_points_earned', 'loyalty_points_redeemed',
            ]);
        });
    }
};
