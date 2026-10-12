<?php

namespace App\Http\Requests\Reservation;

use Illuminate\Foundation\Http\FormRequest;

class CancelReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transition', $this->route('reservation')) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => 'nullable|string',
        ];
    }
}
