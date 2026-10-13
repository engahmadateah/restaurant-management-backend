<?php

namespace App\Actions\Reservations;

use App\Enums\ReservationStatus;
use App\Models\AppNotification;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Reservations\ReservationTableService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateReservationAction
{
    public function __construct(private ReservationTableService $tables) {}

    public function execute(array $data, User $by): Reservation
    {
        $reservation = DB::transaction(function () use ($data, $by) {
            $this->tables->assertNotInThePast($data['reservation_date'], $data['reservation_time']);

           
            $this->tables->assertFits(
                $data['table_id'] ?? null,
                (int) $data['party_size'],
                $data['reservation_date'],
                $data['reservation_time']
            );

            return Reservation::create(Arr::only($data, [
                'table_id', 'customer_id', 'customer_name', 'customer_phone',
                'party_size', 'reservation_date', 'reservation_time', 'notes',
            ]) + [
                'user_id' => $by->id,
                'status' => ReservationStatus::Pending,
            ]);
        });

        AppNotification::notifyRole(
            'admin',
            'reservation',
            'حجز جديد',
            'في طلب حجز جديد بتاريخ ' . $reservation->reservation_date->format('Y-m-d') . ' الساعة ' . $reservation->reservation_time,
            ['reservation_id' => $reservation->id]
        );

        return $reservation;
    }
}
