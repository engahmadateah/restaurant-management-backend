<?php

namespace App\Http\Requests\Payroll;

use App\Enums\PayrollStatus;
use App\Models\Payroll;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Payroll::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'nullable|integer',
            'status' => ['nullable', Rule::enum(PayrollStatus::class)],
        ];
    }
}
