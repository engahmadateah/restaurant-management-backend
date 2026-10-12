<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // بنضيف صلاحيات دقيقة فوق الـ role الأساسي:
    // - extra_permissions: صلاحيات إضافية ممنوحة للمستخدم فوق صلاحيات دوره الافتراضية
    // - revoked_permissions: صلاحيات مسحوبة منه حتى لو دوره بيدّيه ياها افتراضيًا
    // مثال: كاشير بدك تديه يشوف التقارير بس ما تديه يعدلها:
    //   extra_permissions: ["reports.view"], revoked_permissions: ["reports.export"]
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('extra_permissions')->nullable()->after('is_active');
            $table->json('revoked_permissions')->nullable()->after('extra_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['extra_permissions', 'revoked_permissions']);
        });
    }
};
