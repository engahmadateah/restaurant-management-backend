<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * إغلاق الورديات المنسية. الموظف نسي يسجّل انصراف فضلت الوردية مفتوحة:
 *  - ما بيقدر يسجّل حضور اليوم التاني ("عندك وردية مفتوحة")
 *  - لو سجّل انصراف بعد يومين، الوردية بتنحسب 48 ساعة وبتنزل بالراتب كأنها حقيقية
 *
 * الحل: الوردية بتنسكر عند clock_in + shift_max_hours (مش عند "هلأ")، بتتعلّم
 * auto_closed، وبيوصل إشعار للموظف وللمدراء ليراجعوها ويصلّحوها قبل الرواتب.
 */
class ShiftAutoCloser
{
    /**
     * @return int عدد الورديات يلي انسكرت
     */
    public function closeStale(?int $userId = null): int
    {
        $maxMinutes = Shift::maxMinutes();

        $query = Shift::query()
            ->whereNull('clock_out')
            ->where('clock_in', '<=', now()->subMinutes($maxMinutes));

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $closed = [];

        $query->orderBy('id')->chunkById(100, function ($shifts) use ($maxMinutes, &$closed) {
            foreach ($shifts as $shift) {
                if ($this->close($shift, $maxMinutes)) {
                    $closed[] = $shift;
                }
            }
        });

        $this->notifyManagers($closed);

        return count($closed);
    }

    /**
     * بيسكّر وردية وحدة عند الحد الأقصى. بيرجّع false إذا انسكرت من جهة تانية بنفس اللحظة.
     */
    public function close(Shift $shift, ?int $maxMinutes = null): bool
    {
        $maxMinutes ??= Shift::maxMinutes();

        $closed = DB::transaction(function () use ($shift, $maxMinutes) {
            $locked = Shift::whereKey($shift->id)->lockForUpdate()->first();

            if (! $locked || $locked->clock_out !== null) {
                return false;
            }

            $note = 'انسكرت تلقائياً: الموظف ما سجّل انصراف خلال ' . round($maxMinutes / 60, 1) . ' ساعة';

            $locked->update([
                'clock_out' => $locked->clock_in->copy()->addMinutes($maxMinutes),
                'auto_closed' => true,
                'notes' => trim(($locked->notes ? $locked->notes . "\n" : '') . $note),
            ]);

            $shift->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($closed) {
            AppNotification::notifyUser(
                $shift->user_id,
                'shift',
                'انسكرت ورديتك تلقائياً',
                'نسيت تسجّل انصراف، فانسكرت ورديتك عند الحد الأقصى (' . round($maxMinutes / 60, 1) . ' ساعة). المدير رح يراجعها.',
            );
        }

        return $closed;
    }

    /** إشعار واحد ملخّص لكل مدير (مش إشعار لكل وردية) */
    private function notifyManagers(array $closed): void
    {
        if ($closed === []) {
            return;
        }

        $names = User::withTrashed()->whereIn('id', array_unique(array_map(fn ($s) => $s->user_id, $closed)))
            ->pluck('name')->implode('، ');

        $message = 'انسكرت ' . count($closed) . ' وردية تلقائياً لأن الموظف نسي الانصراف (' . $names . '). راجع أوقاتها قبل توليد الرواتب.';

        User::where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermission('shifts.manage_all'))
            ->each(fn (User $u) => AppNotification::notifyUser($u->id, 'shift', 'ورديات انسكرت تلقائياً', $message));
    }
}
