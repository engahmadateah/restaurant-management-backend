<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
         
            $table->boolean('track_stock')->default(false)->after('is_available');
            $table->integer('stock_quantity')->default(0)->after('track_stock');
            $table->integer('low_stock_threshold')->default(5)->after('stock_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn(['track_stock', 'stock_quantity', 'low_stock_threshold']);
        });
    }
};
