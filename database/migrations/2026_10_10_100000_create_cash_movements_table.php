<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// حركات نقدية يدوية على الصندوق المفتوح:
//  - expense    : مصروف مدفوع من الدرج (مثلاً دفعت للمورّد)  = بينقص من المتوقع
//  - withdrawal : سحب نقدي من الصندوق (مثلاً لخزنة المدير)    = بينقص من المتوقع
//  - deposit    : إيداع بالصندوق (مثلاً تزويد فراطة)          = بيزيد على المتوقع
// المصروف بيتسجّل كمان بجدول expenses (expense_id) حتى يدخل بتقرير الأرباح.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            // سجل مالي: ما بيتحذف الصندوق وهو عليه حركات
            $table->foreignId('cash_drawer_id')->constrained('cash_drawers')->restrictOnDelete();
            // مين سجّل الحركة (ممكن يكون المدير عن كاشير)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type'); // expense | withdrawal | deposit
            $table->decimal('amount', 12, 2);
            $table->string('reason');
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamps();

            $table->index(['cash_drawer_id', 'type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
