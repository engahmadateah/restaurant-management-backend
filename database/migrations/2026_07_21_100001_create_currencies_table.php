<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// كل الأسعار بالنظام (menu_items.price، orders...) مخزّنة دايمًا بالعملة
// الأساسية (اللي فيها is_default = true). أي عملة تانية هي بس لغرض
// العرض/الفوترة للزبون: سعر التحويل بيتحسب لحظيًا من exchange_rate
// (قيمة وحدة من العملة الأساسية بالعملة التانية) وما بينخزّن بالطلب.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique(); // ISO 4217: SYP, USD, EUR...
            $table->string('name');
            $table->string('symbol', 8);
            $table->decimal('exchange_rate', 12, 6)->default(1); // 1 وحدة من العملة الأساسية = كم بهاد العملة
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
