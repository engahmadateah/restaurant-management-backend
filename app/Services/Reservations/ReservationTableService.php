<?php

namespace App\Services\Reservations;

use App\Enums\ReservationStatus;
use App\Enums\TableStatus;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReservationTableService
{
    public function assertNotInThePast(string $date, string $time): void
    {
        if (Carbon::parse(Carbon::parse($date)->toDateString() . ' ' . substr($time, 0, 5))->isPast()) {
            throw ValidationException::withMessages([
                'reservation_time' => ['وقت الحجز فات، اختر وقت قادم'],
            ]);
        }
    }

    /**
     * بيقفل صف الطاولة ثم بيتحقق من السعة ومن عدم التعارض.
     * لازم يتنادى جوا DB::transaction حتى القفل يضل ساري لحد ما ينحفظ الحجز.
     */
    public function assertFits(?int $tableId, int $partySize, string $date, string $time, ?int $ignoreReservationId = null): void
    {
        if (! $tableId) {
            return;
        }

        $table = RestaurantTable::whereKey($tableId)->lockForUpdate()->firstOrFail();

        if ($partySize > (int) $table->capacity) {
            throw ValidationException::withMessages([
                'party_size' => ['عدد الأشخاص أكبر من سعة الطاولة (' . $table->capacity . ')'],
            ]);
        }

        $this->assertIsFree($tableId, $date, $time, $ignoreReservationId);
    }

    /** بيحط الطاولة "محجوزة" فقط إذا حان وقت الحجز وكانت متاحة (ما بيكتب فوق طاولة مشغولة) */
    public function holdIfDue(Reservation $reservation): void
    {
        if (! $reservation->table_id || ! $reservation->shouldHoldTable()) {
            return;
        }

        RestaurantTable::whereKey($reservation->table_id)
            ->where('status', TableStatus::Available->value)
            ->update(['status' => TableStatus::Reserved->value]);
    }

    public function occupy(?int $tableId): void
    {
        if (! $tableId) {
            return;
        }

        RestaurantTable::whereKey($tableId)->update(['status' => TableStatus::Occupied->value]);
    }

    /** بيفرّج الطاولة المحجوزة إلا إذا في حجز مؤكد تاني ماسكها هلأ */
    public function release(?int $tableId, int $exceptReservationId): void
    {
        if (! $tableId) {
            return;
        }

        $heldByAnother = Reservation::where('table_id', $tableId)
            ->where('status', ReservationStatus::Confirmed->value)
            ->where('id', '!=', $exceptReservationId)
            ->get()
            ->contains(fn (Reservation $r) => $r->shouldHoldTable());

        if ($heldByAnother) {
            return;
        }

        RestaurantTable::whereKey($tableId)
            ->where('status', TableStatus::Reserved->value)
            ->update(['status' => TableStatus::Available->value]);
    }

    /** ما في حجز فعّال عالطاولة نفسها بفارق أقل من ساعتين عن الوقت المطلوب */
    private function assertIsFree(int $tableId, string $date, string $time, ?int $ignoreReservationId): void
    {
        $requested = $this->toMinutes($time);

        $conflicts = Reservation::where('table_id', $tableId)
            ->whereDate('reservation_date', $date)
            ->whereIn('status', ReservationStatus::activeValues())
            ->when($ignoreReservationId, fn ($q) => $q->where('id', '!=', $ignoreReservationId))
            ->get();

        foreach ($conflicts as $conflict) {
            if (abs($this->toMinutes($conflict->reservation_time) - $requested) < 120) {
                throw ValidationException::withMessages([
                    'table_id' => ['هاي الطاولة محجوزة تقريباً بنفس الوقت، اختر طاولة أو وقت ثاني'],
                ]);
            }
        }
    }

    private function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $h * 60 + $m;
    }
}
