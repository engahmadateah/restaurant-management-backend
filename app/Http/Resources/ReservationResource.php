<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_id' => $this->table_id,
            'customer_id' => $this->customer_id,
            'user_id' => $this->user_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'party_size' => $this->party_size,
            'reservation_date' => $this->reservation_date,
            'reservation_time' => $this->reservation_time,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

         
            'table' => $this->whenLoaded('table', fn ($t) => new RestaurantTableResource($t)),
            'customer' => $this->whenLoaded('customer', fn ($c) => new CustomerResource($c)),
            'user' => $this->whenLoaded('user', fn ($u) => new UserSummaryResource($u)),
        ];
    }
}
