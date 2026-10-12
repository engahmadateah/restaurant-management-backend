<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Reservations\ReservationTableService;
use Illuminate\Support\Facades\DB;

class DeleteReservationAction
{
    public function __construct(private ReservationTableService $tables) {}

    public function execute(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            // حذف حجز مؤكد ماسك طاولة لازم يفرّجها
            if ($reservation->status === ReservationStatus::Confirmed) {
                $this->tables->release($reservation->table_id, $reservation->id);
            }

            $reservation->delete();
        });
    }
}
