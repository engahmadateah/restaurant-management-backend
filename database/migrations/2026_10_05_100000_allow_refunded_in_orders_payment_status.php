<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// عمود payment_status كان enum(unpaid, partial, paid) بينما RefundController
// بيكتب القيمة 'refunded' عليه، وهاد بيفشل (CHECK/ENUM constraint).
// منحوّله لـ string حتى يقبل القيمة بدون ما نكسر البيانات الحالية.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('unpaid')->change();
        });
    }

    public function down(): void
    {
        // ما منرجّع enum عن قصد: ممكن يكون فيه صفوف 'refunded' موجودة.
    }
};
