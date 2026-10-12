<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune {--hours=24 : احذف المفاتيح الأقدم من هالمدة}';

    protected $description = 'حذف مفاتيح Idempotency القديمة';

    public function handle(): int
    {
        $deleted = DB::table('idempotency_keys')
            ->where('created_at', '<', now()->subHours((int) $this->option('hours')))
            ->delete();

        $this->info("تم حذف {$deleted} مفتاح.");

        return self::SUCCESS;
    }
}
