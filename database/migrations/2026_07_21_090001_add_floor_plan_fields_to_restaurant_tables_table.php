<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            // لو null معناها الطاولة لسا ما تحطت على خطة مرئية (بترجع بقائمة نصية بس)
            $table->foreignId('floor_id')->nullable()->after('id')
                ->constrained('floors')->nullOnDelete();

            // إحداثيات وأبعاد الطاولة فوق لوحة الطابق (بالبيكسل، من الزاوية العلوية اليسرى)
            $table->float('pos_x')->nullable()->after('status');
            $table->float('pos_y')->nullable()->after('pos_x');
            $table->unsignedInteger('width')->default(80)->after('pos_y');
            $table->unsignedInteger('height')->default(80)->after('width');
            $table->unsignedInteger('rotation')->default(0)->after('height'); // بالدرجات 0-359
            $table->string('shape')->default('rectangle')->after('rotation'); // rectangle|circle|square
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('floor_id');
            $table->dropColumn(['pos_x', 'pos_y', 'width', 'height', 'rotation', 'shape']);
        });
    }
};
