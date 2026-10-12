<?php

namespace App\Actions\Payroll;

use App\Enums\PayrollStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payroll;
use Illuminate\Support\Facades\DB;

/** تعديل الاستقطاعات/الملاحظات على كشف لسا مسودة (قبل الاعتماد) */
class UpdatePayrollAction
{
    public function execute(Payroll $payroll, array $data): Payroll
    {
        return DB::transaction(function () use ($payroll, $data) {
            $locked = Payroll::whereKey($payroll->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PayrollStatus::Draft) {
                throw new BusinessRuleException('ما فيك تعدّل كشف بعد ما اتاعتمد');
            }

            if (isset($data['deductions'])) {
                $data['net_pay'] = round((float) $locked->gross_pay - $data['deductions'], 2);
            }

            $locked->update($data);

            return $locked;
        });
    }
}
