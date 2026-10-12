<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // true = الموظف لازم يغيّر كلمة السر قبل ما يقدر يستخدم النظام
            // (بتنحط تلقائياً لما الأدمن ينشئ حساب أو يصفّر كلمة السر).
            // الافتراضي false حتى الحسابات الموجودة حالياً ما تتأثر.
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
