<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تخزين نتيجة الطلبات يلي وصلها هيدر Idempotency-Key (إنشاء طلب / تسجيل دفعة)
// حتى لو انقطع النت أو انضغط الزر مرتين، نفس الطلب ما بينعمل مرتين.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('method', 10);
            $table->string('path');
            $table->char('request_hash', 64);
            // null = الطلب لسا عم ينعالج
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('content_type')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // المفتاح خاص بكل مستخدم: كاشيرين مختلفين ممكن يصدف يولّدوا نفس النص
            $table->unique(['user_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
