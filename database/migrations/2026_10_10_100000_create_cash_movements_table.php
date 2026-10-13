<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
           
            $table->foreignId('cash_drawer_id')->constrained('cash_drawers')->restrictOnDelete();

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
