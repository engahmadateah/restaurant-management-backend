<?php

namespace App\Console\Commands;

use App\Services\ShiftAutoCloser;
use Illuminate\Console\Command;

class AutoCloseShifts extends Command
{
    protected $signature = 'shifts:auto-close';

    protected $description = 'إغلاق الورديات المفتوحة أكتر من shift_max_hours (الموظف نسي يسجّل انصراف)';

    public function handle(ShiftAutoCloser $closer): int
    {
        $count = $closer->closeStale();

        $this->info("تم إغلاق {$count} وردية.");

        return self::SUCCESS;
    }
}
