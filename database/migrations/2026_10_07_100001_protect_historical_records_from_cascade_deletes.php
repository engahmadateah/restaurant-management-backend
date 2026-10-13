<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


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
