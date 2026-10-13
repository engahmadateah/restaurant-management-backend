<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RestaurantTableController extends Controller
{
    public function index()
    {
        return RestaurantTable::orderBy('table_number')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'table_number' => 'required|string|unique:restaurant_tables,table_number',
            'capacity' => 'integer|min:1',
            'status' => 'in:available,occupied,reserved',
            'floor_id' => 'nullable|exists:floors,id',
            'pos_x' => 'nullable|numeric|min:0',
            'pos_y' => 'nullable|numeric|min:0',
            'width' => 'sometimes|integer|min:20',
            'height' => 'sometimes|integer|min:20',
            'rotation' => 'sometimes|integer|min:0|max:359',
            'shape' => 'sometimes|in:rectangle,circle,square',
        ]);

        $table = RestaurantTable::create($validated);

        return response()->json($table, 201);
    }

    public function show(RestaurantTable $restaurantTable)
    {
        return $restaurantTable->load('orders');
    }

    public function update(Request $request, RestaurantTable $restaurantTable)
    {
        $validated = $request->validate([
            'table_number' => 'sometimes|required|string|unique:restaurant_tables,table_number,' . $restaurantTable->id,
            'capacity' => 'integer|min:1',
            'status' => 'in:available,occupied,reserved',
            'floor_id' => 'nullable|exists:floors,id',
            'pos_x' => 'nullable|numeric|min:0',
            'pos_y' => 'nullable|numeric|min:0',
            'width' => 'sometimes|integer|min:20',
            'height' => 'sometimes|integer|min:20',
            'rotation' => 'sometimes|integer|min:0|max:359',
            'shape' => 'sometimes|in:rectangle,circle,square',
        ]);

        $restaurantTable->update($validated);

        return response()->json($restaurantTable);
    }

    public function destroy(RestaurantTable $restaurantTable)
    {
        $restaurantTable->delete();

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }

    
    public function qr(RestaurantTable $restaurantTable)
    {
        if (! $restaurantTable->qr_token) {
            $restaurantTable->forceFill(['qr_token' => Str::random(40)])->save();
        }

        return $this->qrResponse($restaurantTable);
    }

  
    public function rotateQr(RestaurantTable $restaurantTable)
    {
        $restaurantTable->forceFill(['qr_token' => Str::random(40)])->save();

        return $this->qrResponse($restaurantTable);
    }

    private function qrResponse(RestaurantTable $table)
    {
        return response()->json([
            'table_id' => $table->id,
            'table_number' => $table->table_number,
            'token' => $table->qr_token,
        ]);
    }
}
