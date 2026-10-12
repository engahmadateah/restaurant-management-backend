<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::orderBy('sort_order')->get();

        $lang = $request->get('lang');
        if ($lang) {
            $categories = $categories->map(function (Category $category) use ($lang) {
                $data = $category->toArray();
                $data['name'] = $category->translate('name', $lang);

                return $data;
            });
        }

        return $categories;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // translations: {"en": {"name": "Appetizers"}, "fr": {"name": "Entrées"}}
            'translations' => 'nullable|array',
            'translations.*.name' => 'nullable|string|max:255',
            'slug' => 'required|string|unique:categories,slug',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            // نسبة ضريبة خاصة بهاد التصنيف (مثلاً المشروبات) تتجاوز الإعداد العام - اتركها فاضية لاستخدام الافتراضي
            'tax_rate' => 'nullable|numeric|min:0|max:1',
        ]);

        $category = Category::create($validated);

        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        return $category->load('menuItems');
    }

    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'translations' => 'nullable|array',
            'translations.*.name' => 'nullable|string|max:255',
            'slug' => 'sometimes|required|string|unique:categories,slug,' . $category->id,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'tax_rate' => 'nullable|numeric|min:0|max:1',
        ]);

        $category->update($validated);

        return response()->json($category);
    }

    public function destroy(Category $category)
    {
        // حذف التصنيف بيمسح أصنافه بالـ cascade على مستوى قاعدة البيانات، فما منسمح فيه
        // إذا فيه أصناف ظاهرة، أو أصناف مؤرشفة (محذوفة) إلها طلبات سابقة (سجل مبيعات).
        if ($category->menuItems()->exists()) {
            throw ValidationException::withMessages([
                'category' => ['التصنيف فيه أصناف. انقلها لتصنيف ثاني أو احذفها أول.'],
            ]);
        }

        if ($category->menuItems()->onlyTrashed()->whereHas('orderItems')->exists()) {
            throw ValidationException::withMessages([
                'category' => ['التصنيف فيه أصناف محذوفة مرتبطة بطلبات سابقة، ما بينحذف حفاظًا على سجل المبيعات.'],
            ]);
        }

        $category->delete();

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }
}
