<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الخيارات يلي فعلًا اختارها الزبون على صنف معيّن جوا طلب.
 * منخزّن اسم وسعر الخيار وقت الطلب (snapshot) حتى لو تغيّر لاحقًا
 * بجدول modifiers، الطلبات القديمة ما تتأثر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_modifier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('extra_price', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifier');
    }
};
