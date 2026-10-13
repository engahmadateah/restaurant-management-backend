<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_drawers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
         
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->decimal('opening_float', 12, 2);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
       
            $table->decimal('cash_sales', 12, 2)->nullable();
            $table->decimal('cash_refunds', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable(); 
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'closed_at']);
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_drawers');
    }
};
