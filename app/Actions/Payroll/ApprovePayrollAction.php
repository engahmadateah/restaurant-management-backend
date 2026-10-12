<?php

namespace App\Actions\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payroll;
use Illuminate\Support\Facades\DB;

class ApprovePayrollAction
{
    public function execute(Payroll $payroll): Payroll
    {
        return DB::transaction(function () use ($payroll) {
            $locked = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayrollStatus::Draft) {
                throw new BusinessRuleException('الكشف معتمد أصلاً');
            }

            $locked->update(['status' => PayrollStatus::Approved, 'approved_at' => now()]);

            return $locked;
        });
    }
}
