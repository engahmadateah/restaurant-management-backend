<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// منخزّن نسبة ومبلغ الضريبة الفعليين لكل سطر وقت إنشاء الطلب (snapshot)،
// حتى لو تغيّرت نسبة الضريبة على الصنف/التصنيف لاحقًا، فاتورة الطلب
// القديمة بتضل صحيحة وما تتأثر.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 4)->default(0)->after('price');
            $table->decimal('tax_amount', 10, 2)->default(0)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'tax_amount']);
        });
    }
};
