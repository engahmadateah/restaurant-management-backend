<?php

namespace App\Http\Requests\Reservation;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Reservation::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'date' => 'nullable|date',
            'table_id' => 'nullable|integer',
        ];
    }
}
