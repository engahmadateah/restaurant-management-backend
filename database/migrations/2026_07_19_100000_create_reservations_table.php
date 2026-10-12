<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // منخزن اسم ورقم هاتف الزبون مباشرة، حتى لو ما كان عنده حساب
            // Customer مسجل مسبقاً (حجز ضيف / walk-in booking)
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();

            $table->unsignedInteger('party_size')->default(1);
            $table->date('reservation_date');
            $table->time('reservation_time');
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'])
                ->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['reservation_date', 'reservation_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
