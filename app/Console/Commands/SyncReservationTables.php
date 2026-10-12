<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use Illuminate\Console\Command;

/**
 * الحجز المؤكد ما بيمسك الطاولة من لحظة التأكيد (ممكن يكون بعد أيام)، بس لما يقرب
 * موعده. هاد الأمر بيمر كل دقيقة ويحوّل الطاولات المتاحة لـ "محجوزة" للحجوزات المؤكدة
 * يلي صار وقتها (Reservation::shouldHoldTable). لازم الـ scheduler يكون شغال.
 */
class SyncReservationTables extends Command
{
    protected $signature = 'reservations:sync-tables';

    protected $description = 'Mark tables as reserved for confirmed reservations that are about to start';

    public function handle(): int
    {
        $held = 0;

        Reservation::with('table')
            ->where('status', 'confirmed')
            ->whereNotNull('table_id')
            ->whereDate('reservation_date', now()->toDateString())
            ->get()
            ->filter(fn (Reservation $r) => $r->shouldHoldTable())
            ->each(function (Reservation $r) use (&$held) {
                if ($r->table && $r->table->status === 'available') {
                    $r->table->update(['status' => 'reserved']);
                    $held++;
                }
            });

        $this->info("Reserved {$held} table(s).");

        return self::SUCCESS;
    }
}
