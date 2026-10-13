<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('tendered_amount', 10, 2)->nullable()->after('amount');
            $table->decimal('change_amount', 10, 2)->nullable()->after('tendered_amount');
        });

   
        DB::table('payments')->where('method', 'cash')->update([
            'tendered_amount' => DB::raw('amount'),
            'change_amount' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['tendered_amount', 'change_amount']);
        });
    }
};
