<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantTableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_number' => $this->table_number,
            'capacity' => $this->capacity,
            'status' => $this->status,
            'floor_id' => $this->floor_id,
            'pos_x' => $this->pos_x,
            'pos_y' => $this->pos_y,
            'width' => $this->width,
            'height' => $this->height,
            'rotation' => $this->rotation,
            'shape' => $this->shape,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
