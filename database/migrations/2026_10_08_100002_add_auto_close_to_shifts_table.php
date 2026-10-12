<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // الوردية انسكرت تلقائياً (الموظف نسي يسجّل انصراف) ومحتاجة مراجعة المدير
            $table->boolean('auto_closed')->default(false)->after('clock_out');
            // مين عدّل أوقاتها يدوياً بعد الإغلاق (للمراجعة)
            $table->foreignId('adjusted_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjusted_by');
            $table->dropColumn('auto_closed');
        });
    }
};
