<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    private const PERMISSION = 'reservations.manage';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(self::PERMISSION);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission(self::PERMISSION);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(self::PERMISSION);
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission(self::PERMISSION);
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission(self::PERMISSION);
    }

    /** confirm / cancel / complete / no-show */
    public function transition(User $user, Reservation $reservation): bool
    {
        return $user->hasPermission(self::PERMISSION);
    }
}
