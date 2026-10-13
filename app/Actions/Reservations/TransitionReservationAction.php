<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\AppNotification;
use App\Models\Reservation;
use App\Services\Reservations\ReservationTableService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionReservationAction
{
    public function __construct(private ReservationTableService $tables) {}

  
    public function execute(Reservation $reservation, ReservationStatus $to, array $extra = []): Reservation
    {
        return DB::transaction(function () use ($reservation, $to, $extra) {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            return $this->apply($locked, $to, $extra);
        });
    }

  
    public function apply(Reservation $reservation, ReservationStatus $to, array $extra = []): Reservation
    {
        if (! $reservation->status->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => [$to->transitionError()],
            ]);
        }

        $reservation->update(['status' => $to] + $extra);

        match ($to) {
            ReservationStatus::Confirmed => $this->tables->holdIfDue($reservation),
            ReservationStatus::Completed => $this->tables->occupy($reservation->table_id),
            ReservationStatus::Cancelled, ReservationStatus::NoShow => $this->tables->release($reservation->table_id, $reservation->id),
            ReservationStatus::Pending => null,
        };

        $this->notify($reservation, $to);

        return $reservation;
    }

    private function notify(Reservation $reservation, ReservationStatus $to): void
    {
        if ($to === ReservationStatus::Confirmed) {
            AppNotification::notifyUser(
                $reservation->user_id,
                'reservation',
                'تم تأكيد الحجز',
                'تم تأكيد الحجز رقم ' . $reservation->id . ' بتاريخ ' . $reservation->reservation_date->format('Y-m-d'),
                ['reservation_id' => $reservation->id]
            );
        } elseif ($to === ReservationStatus::Cancelled) {
            AppNotification::notifyUser(
                $reservation->user_id,
                'reservation',
                'تم إلغاء الحجز',
                'تم إلغاء الحجز رقم ' . $reservation->id,
                ['reservation_id' => $reservation->id]
            );
        }
    }
}
