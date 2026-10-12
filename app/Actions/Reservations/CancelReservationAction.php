<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;

class CancelReservationAction
{
    public function __construct(private TransitionReservationAction $transition) {}

    public function execute(Reservation $reservation, ?string $reason): Reservation
    {
        $notes = trim(
            ($reservation->notes ? $reservation->notes . ' | ' : '')
            . 'سبب الإلغاء: ' . ($reason ?? 'غير محدد')
        );

        return $this->transition->execute($reservation, ReservationStatus::Cancelled, ['notes' => $notes]);
    }
}
