<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
            $table->string('delivery_address')->nullable()->after('order_type');
            $table->string('delivery_phone')->nullable()->after('delivery_address');
            $table->decimal('delivery_fee', 10, 2)->default(0)->after('delivery_phone');
            // not_applicable لطلبات الصالة/التيك أواي، والباقي خاص بالتوصيل فقط
            $table->enum('delivery_status', ['not_applicable', 'pending', 'assigned', 'out_for_delivery', 'delivered'])
                ->default('not_applicable')->after('delivery_fee');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropColumn(['delivery_address', 'delivery_phone', 'delivery_fee', 'delivery_status']);
        });
    }
};
