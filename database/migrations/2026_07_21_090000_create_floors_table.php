<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// خطة الطاولات المرئية (Visual Floor Plan): المطعم ممكن يكون فيه أكتر من
// طابق/قسم (طابق أرضي، تراس، حديقة...) وكل واحد فيه لوحة رسم لحاله
// (canvas) بأبعاد معيّنة نرسم فوقها الطاولات.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // مثال: "الطابق الأرضي"، "التراس"
            $table->unsignedInteger('canvas_width')->default(1000);
            $table->unsignedInteger('canvas_height')->default(700);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floors');
    }
};
