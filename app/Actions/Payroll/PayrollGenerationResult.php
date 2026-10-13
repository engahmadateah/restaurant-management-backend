<?php

namespace App\Actions\Payroll;

use Illuminate\Support\Collection;

final class PayrollGenerationResult
{
    /**
     * @param  Collection<int, \App\Models\Payroll>  $generated
     * @param  list<string>  $skipped  موظفين عندهم كشف أصلاً بنفس الفترة بالضبط
     * @param  list<array>  $conflicts  فترة بتتداخل مع كشف موجود (مختلفة عن المطلوبة)
     * @param  list<array>  $warnings  ورديات مفتوحة أو منسية داخل الفترة
     */
    public function __construct(
        public readonly Collection $generated,
        public readonly array $skipped,
        public readonly array $conflicts,
        public readonly array $warnings,
    ) {}

    
    public function allConflicted(): bool
    {
        return $this->generated->isEmpty() && $this->conflicts !== [];
    }

    public function conflictMessage(): string
    {
        $first = $this->conflicts[0];

        return 'الفترة بتتداخل مع كشف موجود لـ ' . $first['user'] . ' (' . $first['period_start'] . ' → ' . $first['period_end']
            . '، ' . $first['status'] . '). إذا كان مسودة احذفه، أو اختار فترة ما بتتداخل.';
    }
}
