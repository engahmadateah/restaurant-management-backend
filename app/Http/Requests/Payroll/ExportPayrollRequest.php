<?php

namespace App\Http\Requests\Payroll;

use App\Models\Payroll;
use Illuminate\Foundation\Http\FormRequest;

class ExportPayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Payroll::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'format' => 'nullable|in:xlsx,csv',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'status' => 'nullable|in:draft,approved,paid',
            'user_id' => 'nullable|exists:users,id',
        ];
    }
}
