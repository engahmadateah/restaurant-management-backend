<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مفاتيح أجنبية كانت بـ cascadeOnDelete على سجلات مالية/تاريخية:
 *  - orders.user_id        حذف موظف = حذف كل طلباته (ومعها الدفعات والاسترجاعات)
 *  - refunds.requested_by  حذف موظف = حذف استرجاعاته
 *  - order_items.menu_item_id  حذف صنف = مسحه من الطلبات القديمة (المجموع ما بيطابق الأصناف)
 *  - payrolls.user_id / shifts.user_id  حذف موظف = حذف رواتبه وحضوره
 *
 * هلأ: الطلبات والاسترجاعات بتبقى (user = null)، والأصناف والرواتب والورديات بتمنع الحذف الفعلي.
 * الحذف العادي من الواجهة صار soft delete (شوف migration إضافة deleted_at) فما بيوصل لهون أصلاً.
 *
 * SQLite (بيئة الاختبارات) بيحتاج إعادة بناء الجدول لتغيير المفاتيح الأجنبية، فبنتخطاها هناك:
 * هالقيود بتنطبق على MySQL/PostgreSQL (الإنتاج).
 */
return new class extends Migration
{
    /** @return array<int, array{table: string, column: string, on: string, nullable: bool, action: string}> */
    private function changes(): array
    {
        return [
            ['table' => 'orders', 'column' => 'user_id', 'on' => 'users', 'nullable' => true, 'action' => 'nullOnDelete'],
            ['table' => 'refunds', 'column' => 'requested_by', 'on' => 'users', 'nullable' => true, 'action' => 'nullOnDelete'],
            ['table' => 'order_items', 'column' => 'menu_item_id', 'on' => 'menu_items', 'nullable' => false, 'action' => 'restrictOnDelete'],
            ['table' => 'payrolls', 'column' => 'user_id', 'on' => 'users', 'nullable' => false, 'action' => 'restrictOnDelete'],
            ['table' => 'shifts', 'column' => 'user_id', 'on' => 'users', 'nullable' => false, 'action' => 'restrictOnDelete'],
        ];
    }

    public function up(): void
    {
        $this->rewire($this->changes());
    }

    public function down(): void
    {
        // رجوع للسلوك القديم (cascade). الأعمدة اللي صارت nullable بتضل nullable عن قصد
        // (إرجاعها NOT NULL بيفشل إذا في صفوف user_id = null).
        $this->rewire(array_map(
            fn (array $c) => ['nullable' => true, 'action' => 'cascadeOnDelete'] + $c,
            $this->changes()
        ));
    }

    private function rewire(array $changes): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach ($changes as $c) {
            Schema::table($c['table'], function (Blueprint $table) use ($c) {
                $table->dropForeign([$c['column']]);
            });

            Schema::table($c['table'], function (Blueprint $table) use ($c) {
                $column = $table->unsignedBigInteger($c['column']);
                if ($c['nullable']) {
                    $column->nullable();
                }
                $column->change();
            });

            Schema::table($c['table'], function (Blueprint $table) use ($c) {
                $foreign = $table->foreign($c['column'])->references('id')->on($c['on']);
                $foreign->{$c['action']}();
            });
        }
    }
};
