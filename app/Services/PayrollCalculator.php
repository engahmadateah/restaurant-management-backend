<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

class PayrollCalculator
{
   
    public function calculate(User $user, Carbon $periodStart, Carbon $periodEnd): array
    {
        $shifts = $user->shifts()
            ->whereNotNull('clock_out')
            ->whereBetween('clock_in', [$periodStart->copy()->startOfDay(), $periodEnd->copy()->endOfDay()])
            ->get();

        $threshold = (float) ($user->weekly_overtime_threshold_hours ?? 40);

        $hoursByWeek = [];
        foreach ($shifts as $shift) {
            $weekKey = $shift->clock_in->format('o-W'); // سنة ISO - رقم الأسبوع
            $hours = $shift->clock_in->diffInMinutes($shift->clock_out) / 60;
            $hoursByWeek[$weekKey] = ($hoursByWeek[$weekKey] ?? 0) + $hours;
        }

        $totalHours = 0;
        $regularHours = 0;
        $overtimeHours = 0;

        foreach ($hoursByWeek as $weekHours) {
            $totalHours += $weekHours;
            $regularHours += min($weekHours, $threshold);
            $overtimeHours += max(0, $weekHours - $threshold);
        }

        $hourlyRate = (float) ($user->hourly_rate ?? 0);
        $overtimeMultiplier = (float) ($user->overtime_multiplier ?? 1.5);

        if ($user->salary_type === 'monthly') {
          
            $regularPay = (float) ($user->monthly_salary ?? 0);
            $overtimePay = 0;
        } else {
            $regularPay = round($regularHours * $hourlyRate, 2);
            $overtimePay = round($overtimeHours * $hourlyRate * $overtimeMultiplier, 2);
        }

        $grossPay = round($regularPay + $overtimePay, 2);

        return [
            'total_hours' => round($totalHours, 2),
            'regular_hours' => round($regularHours, 2),
            'overtime_hours' => round($overtimeHours, 2),
            'regular_pay' => $regularPay,
            'overtime_pay' => $overtimePay,
            'gross_pay' => $grossPay,
        ];
    }
}
