<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FavoriteController extends Controller
{
  
    public function index(Customer $customer)
    {
        return $customer->favorites()->with('category:id,name')->get();
    }


    public function store(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'menu_item_id' => ['required', Rule::exists('menu_items', 'id')->whereNull('deleted_at')],
        ]);

        if ($customer->favorites()->where('menu_item_id', $validated['menu_item_id'])->exists()) {
            return response()->json(['message' => 'الصنف موجود بالمفضّلة أصلاً'], 422);
        }

        $customer->favorites()->attach($validated['menu_item_id']);

        return response()->json($customer->favorites()->with('category:id,name')->get(), 201);
    }


    public function destroy(Customer $customer, MenuItem $menuItem)
    {
        $customer->favorites()->detach($menuItem->id);

        return response()->json(['message' => 'تم حذف الصنف من المفضّلة']);
    }
}
