<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';


    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled, self::NoShow],
            self::Confirmed => [self::Completed, self::Cancelled, self::NoShow],
            self::Cancelled, self::Completed, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function transitionError(): string
    {
        return match ($this) {
            self::Confirmed => 'بس الحجوزات بحالة "قيد الانتظار" ممكن تتأكد',
            self::Completed => 'بس الحجوزات المؤكدة ممكن تتعلّم كمكتملة',
            self::Cancelled => 'هاد الحجز ملغي أو مكتمل أصلاً',
            self::NoShow => 'بس الحجوزات المعلّقة أو المؤكدة ممكن تتعلّم "ما حضر"',
            self::Pending => 'انتقال غير مسموح للحجز',
        };
    }

    public static function activeValues(): array
    {
        return [self::Pending->value, self::Confirmed->value];
    }
}
