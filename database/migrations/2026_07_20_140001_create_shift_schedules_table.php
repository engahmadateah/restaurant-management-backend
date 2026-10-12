<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // جدول جدولة الورديات المسبقة (Shift Scheduling)
    // مختلف عن جدول shifts يلي هو تسجيل حضور/انصراف فعلي (clock-in/out)
    public function up(): void
    {
        Schema::create('shift_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('status', ['scheduled', 'confirmed', 'cancelled', 'completed'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // بيربط الوردية المجدولة بالحضور الفعلي لما الموظف يعمل clock-in (اختياري)
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_schedules');
    }
};
