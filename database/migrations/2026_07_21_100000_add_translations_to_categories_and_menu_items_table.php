<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// دعم تعدد اللغات بالمنيو: name/description الأصليين بيضلوا اللغة
// الافتراضية (عربي)، وعمود translations بيخزّن الترجمات الإضافية:
// { "en": { "name": "Hummus", "description": "..." }, "fr": {...} }
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->json('translations')->nullable()->after('name');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->json('translations')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('translations');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('translations');
        });
    }
};
