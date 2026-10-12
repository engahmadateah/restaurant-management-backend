<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MenuItemController extends Controller
{
    public function index(Request $request)
    {
        $query = MenuItem::with('category');

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('available_only')) {
            $query->where('is_available', $request->boolean('available_only'));
        }

        $items = $query->orderBy('sort_order')->get();

        return $this->localize($items, $request);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            // translations: {"en": {"name": "...", "description": "..."}, "fr": {...}}
            'translations' => 'nullable|array',
            'translations.*.name' => 'nullable|string|max:255',
            'translations.*.description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            // نسبة ضريبة خاصة بهاد الصنف تتجاوز نسبة التصنيف/الإعداد العام (اتركها فاضية لاستخدام الافتراضي)
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'image' => 'nullable|string',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $menuItem = MenuItem::create($validated);

        return response()->json($menuItem->load('category'), 201);
    }

    public function show(Request $request, MenuItem $menuItem)
    {
        $menuItem->load(['category', 'modifierGroups.modifiers']);

        return $this->localize(collect([$menuItem]), $request)->first();
    }

    public function update(Request $request, MenuItem $menuItem)
    {
        $validated = $request->validate([
            'category_id' => 'sometimes|required|exists:categories,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'translations' => 'nullable|array',
            'translations.*.name' => 'nullable|string|max:255',
            'translations.*.description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
            'image' => 'nullable|string',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $menuItem->update($validated);

        return response()->json($menuItem->load('category'));
    }

    /**
     * بيطبّق ترجمة اللغة (?lang=en) وتحويل العملة (?currency=USD) على
     * مجموعة أصناف قبل ما ترجع بالـ response، بدون ما يتغيّر أي شي
     * بقاعدة البيانات (كل شي محسوب لحظيًا).
     */
    private function localize($items, Request $request)
    {
        $lang = $request->get('lang');
        $currencyCode = $request->get('currency');
        $currency = $currencyCode
            ? Currency::where('code', strtoupper($currencyCode))->where('is_active', true)->first()
            : null;

        return $items->map(function (MenuItem $item) use ($lang, $currency) {
            $data = $item->toArray();

            if ($lang) {
                $data['name'] = $item->translate('name', $lang);
                $data['description'] = $item->translate('description', $lang);
            }

            if ($currency) {
                $data['price'] = $currency->convert((float) $item->price);
                $data['currency'] = $currency->code;
                $data['currency_symbol'] = $currency->symbol;
            }

            return $data;
        });
    }

    public function destroy(MenuItem $menuItem)
    {
        $menuItem->delete();

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }

    // رفع صورة لصنف معيّن: POST /api/menu-items/{menuItem}/image
    public function uploadImage(Request $request, MenuItem $menuItem)
    {
        $request->validate([
            'image' => 'required|image|max:2048', // 2MB كحد أقصى
        ]);

        // حذف الصورة القديمة إذا كانت موجودة
        if ($menuItem->image) {
            Storage::disk('public')->delete($menuItem->image);
        }

        $path = $request->file('image')->store('menu-items', 'public');

        $menuItem->update(['image' => $path]);

        return response()->json($menuItem->load('category'));
    }
}