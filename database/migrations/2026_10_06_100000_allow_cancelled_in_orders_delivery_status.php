<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// عمود delivery_status كان enum بدون قيمة "cancelled"، فالطلب الملغي كان يضل
// delivery_status = pending/assigned للأبد ويتحسب "توصيل نشط" بالداشبورد.
// منحوّله لـ string (نفس أسلوب migration payment_status) ومنصلّح البيانات القديمة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_status', 20)->default('not_applicable')->change();
        });

        DB::table('orders')
            ->where('order_type', 'delivery')
            ->where('status', 'cancelled')
            ->where('delivery_status', '!=', 'delivered')
            ->update(['delivery_status' => 'cancelled']);
    }

    public function down(): void
    {
        // ما منرجّع enum عن قصد: ممكن يكون فيه صفوف 'cancelled' موجودة.
    }
};
