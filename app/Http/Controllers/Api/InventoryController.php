<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\Request;

class InventoryController extends Controller
{

    public function index()
    {
        return MenuItem::with('category')
            ->where('track_stock', true)
            ->orderBy('name')
            ->get();
    }

   
    public function lowStock()
    {
        return MenuItem::with('category')
            ->where('track_stock', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')
            ->get();
    }

 
    public function restock(Request $request, MenuItem $menuItem)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $menuItem->increment('stock_quantity', $validated['quantity']);

        return response()->json($menuItem->fresh());
    }

 
    public function updateSettings(Request $request, MenuItem $menuItem)
    {
        $validated = $request->validate([
            'track_stock' => 'required|boolean',
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
        ]);

        
        $menuItem->update(array_filter($validated, fn ($value) => $value !== null));

        return response()->json($menuItem->fresh());
    }
}
