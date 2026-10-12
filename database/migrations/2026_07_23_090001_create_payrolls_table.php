<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');

            // منسنخ (snapshot) إعدادات الراتب وقت توليد كشف الراتب، حتى لو
            // تغيّر أجر الساعة لاحقًا ما يتأثر كشف راتب قديم
            $table->string('salary_type');
            $table->decimal('hourly_rate', 8, 2)->nullable();
            $table->decimal('monthly_salary', 10, 2)->nullable();
            $table->decimal('overtime_multiplier', 4, 2)->default(1.5);

            $table->decimal('total_hours', 8, 2)->default(0);
            $table->decimal('regular_hours', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);

            $table->decimal('regular_pay', 10, 2)->default(0);
            $table->decimal('overtime_pay', 10, 2)->default(0);
            $table->decimal('deductions', 10, 2)->default(0);
            $table->decimal('gross_pay', 10, 2)->default(0);
            $table->decimal('net_pay', 10, 2)->default(0);

            $table->string('status')->default('draft'); // draft -> approved -> paid
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // ما منسمح بتوليد كشفين لنفس الموظف بنفس الفترة بالضبط
            $table->unique(['user_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
