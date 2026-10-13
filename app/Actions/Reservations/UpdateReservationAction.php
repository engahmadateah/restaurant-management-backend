<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Reservations\ReservationTableService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateReservationAction
{
    public function __construct(
        private ReservationTableService $tables,
        private TransitionReservationAction $transition,
    ) {}

    public function execute(Reservation $reservation, array $data): Reservation
    {
        return DB::transaction(function () use ($reservation, $data) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            $newStatus = isset($data['status']) ? ReservationStatus::from($data['status']) : null;
            unset($data['status']);

            $oldTableId = $reservation->table_id;
            $tableId = array_key_exists('table_id', $data) ? $data['table_id'] : $oldTableId;

            $currentDate = $reservation->reservation_date->format('Y-m-d');
            $currentTime = substr((string) $reservation->reservation_time, 0, 5);

            $date = $data['reservation_date'] ?? $currentDate;
            $time = $data['reservation_time'] ?? $currentTime;
            $party = (int) ($data['party_size'] ?? $reservation->party_size);

            $dateChanged = $date !== $currentDate;
            $timeChanged = $time !== $currentTime;
            $scheduleChanged = $dateChanged || $timeChanged
                || (int) $tableId !== (int) $oldTableId
                || $party !== (int) $reservation->party_size;


            if ($scheduleChanged && $reservation->status->isFinal()) {
                throw ValidationException::withMessages([
                    'status' => ['ما بينقدر تعدّل موعد أو طاولة حجز منتهي (' . $reservation->status->value . ')'],
                ]);
            }

            if ($scheduleChanged) {
                if ($dateChanged && Carbon::parse($date)->lt(Carbon::today())) {
                    throw ValidationException::withMessages([
                        'reservation_date' => ['تاريخ الحجز لازم يكون اليوم أو بعده'],
                    ]);
                }

                if ($dateChanged || $timeChanged) {
                    $this->tables->assertNotInThePast($date, $time);
                }

                $this->tables->assertFits($tableId, $party, $date, $time, $reservation->id);
            }

            $reservation->update($data);

           
            if ($reservation->status === ReservationStatus::Confirmed && (int) $tableId !== (int) $oldTableId) {
                $this->tables->release($oldTableId, $reservation->id);
                $this->tables->holdIfDue($reservation);
            }

         
            if ($newStatus !== null && $newStatus !== $reservation->status) {
                $this->transition->apply($reservation, $newStatus);
            }

            return $reservation->fresh(Reservation::WITH);
        });
    }
}
