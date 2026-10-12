<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// حذف موظف أو صنف كان يمسح (بالـ cascade) كل طلباته/أسطر الطلبات القديمة وكشوف الرواتب.
// SoftDeletes = بيختفي من القوائم والاختيارات لكن سجله التاريخي (فواتير، تقارير، رواتب) بيضل سليم.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
