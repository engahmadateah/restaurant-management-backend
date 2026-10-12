<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('payroll')) ?? false;
    }

    public function rules(): array
    {
        $payroll = $this->route('payroll');

        return [
            // الاستقطاعات ما بتتعدى إجمالي الراتب (قبل كان net_pay ممكن يصير سالب)
            'deductions' => ['sometimes', 'numeric', 'min:0', 'max:' . $payroll->gross_pay],
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'deductions.max' => 'الاستقطاعات أكبر من إجمالي الراتب (' . $this->route('payroll')->gross_pay . ')',
        ];
    }
}
