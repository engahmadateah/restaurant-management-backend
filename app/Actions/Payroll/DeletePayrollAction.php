<?php

namespace App\Actions\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payroll;
use Illuminate\Support\Facades\DB;

class DeletePayrollAction
{
    public function execute(Payroll $payroll): void
    {
        DB::transaction(function () use ($payroll) {
            $locked = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayrollStatus::Draft) {
                throw new BusinessRuleException('ما فيك تحذف كشف بعد ما اتاعتمد أو انصرف');
            }

            $locked->delete();
        });
    }
}
