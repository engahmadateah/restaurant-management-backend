<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;

class FloorController extends Controller
{
  
    public function index()
    {
        return Floor::with(['tables' => function ($query) {
            $query->orderBy('table_number');
        }])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'canvas_width' => 'integer|min:200',
            'canvas_height' => 'integer|min:200',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $floor = Floor::create($validated);

        return response()->json($floor, 201);
    }

    public function show(Floor $floor)
    {
        return $floor->load('tables');
    }

    public function update(Request $request, Floor $floor)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'canvas_width' => 'integer|min:200',
            'canvas_height' => 'integer|min:200',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ]);

        $floor->update($validated);

        return response()->json($floor);
    }

    public function destroy(Floor $floor)
    {
       
        if ($floor->tables()->exists()) {
            return response()->json([
                'message' => 'في طاولات مرتبطة بهاد الطابق، لازم تنقلها أو تحذفها أول',
            ], 422);
        }

        $floor->delete();

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }

  
    public function updatePosition(Request $request, RestaurantTable $restaurantTable)
    {
        $validated = $request->validate([
            'floor_id' => 'nullable|exists:floors,id',
            'pos_x' => 'required|numeric|min:0',
            'pos_y' => 'required|numeric|min:0',
            'width' => 'sometimes|integer|min:20',
            'height' => 'sometimes|integer|min:20',
            'rotation' => 'sometimes|integer|min:0|max:359',
            'shape' => 'sometimes|in:rectangle,circle,square',
        ]);

        $restaurantTable->update($validated);

        return response()->json($restaurantTable->load('floor'));
    }


    public function bulkUpdatePositions(Request $request)
    {
        $validated = $request->validate([
            'tables' => 'required|array|min:1',
            'tables.*.id' => 'required|exists:restaurant_tables,id',
            'tables.*.pos_x' => 'required|numeric|min:0',
            'tables.*.pos_y' => 'required|numeric|min:0',
            'tables.*.rotation' => 'sometimes|integer|min:0|max:359',
        ]);

        foreach ($validated['tables'] as $item) {
            RestaurantTable::whereKey($item['id'])->update(array_filter([
                'pos_x' => $item['pos_x'],
                'pos_y' => $item['pos_y'],
                'rotation' => $item['rotation'] ?? null,
            ], fn ($value) => $value !== null));
        }

        return response()->json(['message' => 'تم تحديث المواضع بنجاح']);
    }
}
