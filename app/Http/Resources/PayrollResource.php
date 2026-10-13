<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class PayrollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'salary_type' => $this->salary_type,
            'hourly_rate' => $this->hourly_rate,
            'monthly_salary' => $this->monthly_salary,
            'overtime_multiplier' => $this->overtime_multiplier,
            'total_hours' => $this->total_hours,
            'regular_hours' => $this->regular_hours,
            'overtime_hours' => $this->overtime_hours,
            'regular_pay' => $this->regular_pay,
            'overtime_pay' => $this->overtime_pay,
            'deductions' => $this->deductions,
            'gross_pay' => $this->gross_pay,
            'net_pay' => $this->net_pay,
            'status' => $this->status,
            'generated_by' => $this->generated_by,
            'approved_at' => $this->approved_at,
            'paid_at' => $this->paid_at,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'user' => $this->whenLoaded('user', fn ($u) => new StaffBriefResource($u)),
           
            'generatedBy' => $this->whenLoaded('generatedBy', fn ($u) => new UserSummaryResource($u)),
        ];
    }
}
