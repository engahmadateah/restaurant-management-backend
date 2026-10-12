<?php

namespace App\Http\Requests\Payroll;

use App\Models\Payroll;
use Illuminate\Foundation\Http\FormRequest;

class GeneratePayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Payroll::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start|before_or_equal:today',
            'user_id' => 'nullable|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'period_end.before_or_equal' => 'الفترة لسا ما خلصت. اختار تاريخ نهاية أقصاه اليوم.',
        ];
    }
}
