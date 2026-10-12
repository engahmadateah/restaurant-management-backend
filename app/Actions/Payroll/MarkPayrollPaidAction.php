<?php

namespace App\Actions\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payroll;
use Illuminate\Support\Facades\DB;

class MarkPayrollPaidAction
{
    public function execute(Payroll $payroll): Payroll
    {
        return DB::transaction(function () use ($payroll) {
            $locked = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayrollStatus::Approved) {
                throw new BusinessRuleException('لازم تعتمد الكشف أول قبل ما تأشره مدفوع');
            }

            $locked->update(['status' => PayrollStatus::Paid, 'paid_at' => now()]);

            return $locked;
        });
    }
}
