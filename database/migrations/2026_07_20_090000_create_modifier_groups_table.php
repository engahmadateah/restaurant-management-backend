<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مجموعات الخيارات الإضافية للصنف (مثلاً: "الحجم"، "الإضافات"، "درجة النضج"...).
 * كل صنف ممكن يكون إله أكتر من مجموعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // مثلاً: الحجم / الإضافات
            // إذا true لازم الزبون يختار خيار وحد على الأقل من هاد المجموعة
            $table->boolean('is_required')->default(false);
            // أقصى عدد خيارات مسموح يختارهم الزبون من هاد المجموعة (1 = اختيار وحيد زي الحجم)
            $table->unsignedTinyInteger('max_selections')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_groups');
    }
};
