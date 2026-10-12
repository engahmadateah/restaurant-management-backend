<?php

namespace App\Actions\Payroll;

use App\Enums\PayrollStatus;
use App\Models\Payroll;
use App\Models\User;
use App\Services\PayrollCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * توليد كشوفات راتب لفترة معيّنة - لموظف واحد أو لكل الموظفين النشيطين.
 *
 * - الفترة لازم تكون خلصت (بتتحقق منها GeneratePayrollRequest): كشف لفترة لسا ما خلصت بيحسب
 *   جزء من الأيام وبعدين بيمنع توليد الكشف الصحيح بسبب منع التداخل.
 * - ممنوع تتداخل فترتين لنفس الموظف (أي حالة): قبل، فترة 1-31 وفترة 15-15 تاني شهر كانوا
 *   بيتولّدوا مع بعض وبتنحسب ورديات 15-31 مرتين.
 *   نفس الفترة بالضبط = skipped. تداخل مختلف = conflicts.
 * - warnings: موظفين عندهم ورديات مفتوحة أو منسية (انسكرت تلقائياً) داخل الفترة، لأنها
 *   بتأثر على الأرقام: المفتوحة ما بتنحسب أبداً، والمنسية بتنحسب عند الحد الأقصى.
 */
class GeneratePayrollsAction
{
    public function __construct(private PayrollCalculator $calculator) {}

    public function execute(array $data, User $by): PayrollGenerationResult
    {
        $periodStart = Carbon::parse($data['period_start']);
        $periodEnd = Carbon::parse($data['period_end']);

        $users = ! empty($data['user_id'])
            ? User::where('id', $data['user_id'])->get()
            : User::where('is_active', true)->get();

        if ($users->isEmpty()) {
            throw ValidationException::withMessages(['user_id' => ['ما في موظفين نشيطين لتوليد كشوفات لهم']]);
        }

        $generated = [];
        $skipped = [];
        $conflicts = [];
        $warnings = [];

        foreach ($users as $user) {
            $outcome = $this->generateFor($user, $periodStart, $periodEnd, $by);

            if (isset($outcome['conflict'])) {
                $existing = $outcome['conflict'];
                $exact = $existing->period_start->toDateString() === $periodStart->toDateString()
                    && $existing->period_end->toDateString() === $periodEnd->toDateString();

                if ($exact) {
                    $skipped[] = $user->name;
                } else {
                    $conflicts[] = [
                        'user_id' => $user->id,
                        'user' => $user->name,
                        'payroll_id' => $existing->id,
                        'period_start' => $existing->period_start->toDateString(),
                        'period_end' => $existing->period_end->toDateString(),
                        'status' => $existing->status->value,
                    ];
                }

                continue;
            }

            $generated[] = $outcome['payroll'];

            if ($warning = $this->shiftWarning($user, $periodStart, $periodEnd)) {
                $warnings[] = $warning;
            }
        }

        return new PayrollGenerationResult(collect($generated), $skipped, $conflicts, $warnings);
    }

    /** @return array{conflict: Payroll}|array{payroll: Payroll} */
    private function generateFor(User $user, Carbon $periodStart, Carbon $periodEnd, User $by): array
    {
        return DB::transaction(function () use ($user, $periodStart, $periodEnd, $by) {
            // قفل صف الموظف: مديرين بيولّدوا بنفس اللحظة ما لازم يمرقوا فحص التداخل مع بعض
            User::whereKey($user->id)->lockForUpdate()->first();

            $conflict = Payroll::where('user_id', $user->id)
                // whereDate: العمود date بس Eloquent بيخزّنه مع وقت (00:00:00) على بعض الـ DB،
                // فمقارنة نصية مباشرة كانت تفوّت التداخل عند حدود الفترة
                ->whereDate('period_start', '<=', $periodEnd->toDateString())
                ->whereDate('period_end', '>=', $periodStart->toDateString())
                ->orderBy('period_start')
                ->first();

            if ($conflict) {
                return ['conflict' => $conflict];
            }

            $calc = $this->calculator->calculate($user, $periodStart, $periodEnd);

            return ['payroll' => Payroll::create([
                'user_id' => $user->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'salary_type' => $user->salary_type,
                'hourly_rate' => $user->hourly_rate,
                'monthly_salary' => $user->monthly_salary,
                'overtime_multiplier' => $user->overtime_multiplier,
                'total_hours' => $calc['total_hours'],
                'regular_hours' => $calc['regular_hours'],
                'overtime_hours' => $calc['overtime_hours'],
                'regular_pay' => $calc['regular_pay'],
                'overtime_pay' => $calc['overtime_pay'],
                'gross_pay' => $calc['gross_pay'],
                'net_pay' => $calc['gross_pay'], // بدون استقطاعات افتراضيًا - تنعدّل يدويًا قبل الاعتماد
                'status' => PayrollStatus::Draft,
                'generated_by' => $by->id,
            ])];
        });
    }

    private function shiftWarning(User $user, Carbon $periodStart, Carbon $periodEnd): ?array
    {
        $range = [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()];

        $open = $user->shifts()->whereNull('clock_out')->whereBetween('clock_in', $range)->count();
        $autoClosed = $user->shifts()->where('auto_closed', true)->whereBetween('clock_in', $range)->count();

        if ($open === 0 && $autoClosed === 0) {
            return null;
        }

        return [
            'user_id' => $user->id,
            'user' => $user->name,
            'open_shifts' => $open,
            'auto_closed_shifts' => $autoClosed,
        ];
    }
}
