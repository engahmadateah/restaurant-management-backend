<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // hourly: بينحسب راتبه من ساعات الورديات الفعلية (clock-in/out)
            // monthly: راتب شهري ثابت (بيتسجّل حضوره برضو بس ما بيأثر على المبلغ)
            $table->string('salary_type')->default('hourly')->after('role');
            $table->decimal('hourly_rate', 8, 2)->nullable()->after('salary_type');
            $table->decimal('monthly_salary', 10, 2)->nullable()->after('hourly_rate');

            // ساعات الأسبوع اللي بعدها تحتسب الساعة overtime (وقت إضافي)
            $table->unsignedInteger('weekly_overtime_threshold_hours')->default(40)->after('monthly_salary');
            // مضاعف أجر الساعة الإضافية (1.5 يعني وقت ونص)
            $table->decimal('overtime_multiplier', 4, 2)->default(1.5)->after('weekly_overtime_threshold_hours');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'salary_type', 'hourly_rate', 'monthly_salary',
                'weekly_overtime_threshold_hours', 'overtime_multiplier',
            ]);
        });
    }
};
