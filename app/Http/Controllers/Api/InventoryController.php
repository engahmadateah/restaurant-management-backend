<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    // كل الأصناف يلي بتتبّع مخزون
    public function index()
    {
        return MenuItem::with('category')
            ->where('track_stock', true)
            ->orderBy('name')
            ->get();
    }

    // الأصناف يلي وصلت أو تحت حد المخزون المنخفض
    public function lowStock()
    {
        return MenuItem::with('category')
            ->where('track_stock', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')
            ->get();
    }

    // تزويد المخزون (وصول توريد جديد مثلاً)
    public function restock(Request $request, MenuItem $menuItem)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $menuItem->increment('stock_quantity', $validated['quantity']);

        return response()->json($menuItem->fresh());
    }

    // تفعيل/تعديل إعدادات تتبع المخزون لصنف معيّن
    public function updateSettings(Request $request, MenuItem $menuItem)
    {
        $validated = $request->validate([
            'track_stock' => 'required|boolean',
            'stock_quantity' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
        ]);

        // stock_quantity و low_stock_threshold أعمدة NOT NULL: القيمة الفاضية (null) كانت
        // تكسر الـ UPDATE بخطأ 500. null هون يعني "ما تغيّر القيمة الحالية"
        $menuItem->update(array_filter($validated, fn ($value) => $value !== null));

        return response()->json($menuItem->fresh());
    }
}
