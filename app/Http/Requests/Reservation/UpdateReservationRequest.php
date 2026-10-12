<?php

namespace App\Http\Requests\Reservation;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('reservation')) ?? false;
    }

    public function rules(): array
    {
        return [
            'table_id' => 'nullable|exists:restaurant_tables,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'party_size' => 'sometimes|required|integer|min:1',
            'reservation_date' => 'sometimes|required|date',
            'reservation_time' => 'sometimes|required|date_format:H:i',
            'status' => ['sometimes', 'required', Rule::enum(ReservationStatus::class)],
            'notes' => 'nullable|string',
        ];
    }
}
