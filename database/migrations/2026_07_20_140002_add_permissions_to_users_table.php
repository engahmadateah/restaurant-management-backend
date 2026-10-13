<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('extra_permissions')->nullable()->after('is_active');
            $table->json('revoked_permissions')->nullable()->after('extra_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['extra_permissions', 'revoked_permissions']);
        });
    }
};
