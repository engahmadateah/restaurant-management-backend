<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ضريبة قابلة للتخصيص: null يعني "استخدم نسبة الضريبة الافتراضية"
// (بترث من التصنيف، وإذا التصنيف مالوش نسبة خاصة فيه بترث من
// settings.tax_rate العامة). حط نسبة صريحة (حتى 0) لتجاوز الافتراضي،
// مثال واقعي: المشروبات الغازية عليها ضريبة أعلى من الأكل.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 4)->nullable()->after('is_active');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 4)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
