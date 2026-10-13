<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
       
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 20)->default('pos')->after('order_type');
            $table->string('public_token', 40)->nullable()->unique()->after('source');
            $table->string('public_request_id', 64)->nullable()->unique()->after('public_token');
            $table->index('source');
        });

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->string('qr_token', 40)->nullable()->unique()->after('table_number');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn('qr_token');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropUnique(['public_request_id']);
            $table->dropUnique(['public_token']);
            $table->dropColumn(['source', 'public_token', 'public_request_id']);
        });
    }
};
