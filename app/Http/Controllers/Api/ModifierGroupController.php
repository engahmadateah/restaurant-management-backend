<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Http\Request;

/**
 * إدارة مجموعات الخيارات الإضافية (Modifiers) لكل صنف بالمنيو.
 * مثال: صنف "بيتزا" -> مجموعة "الحجم" (وسط/كبير) + مجموعة "الإضافات" (جبنة/زيتون...).
 */
class ModifierGroupController extends Controller
{
    // كل مجموعات الخيارات لصنف معيّن مع الخيارات جواها
    public function index(MenuItem $menuItem)
    {
        return $menuItem->modifierGroups()->with('modifiers')->orderBy('sort_order')->get();
    }

    public function store(Request $request, MenuItem $menuItem)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_required' => 'boolean',
            'max_selections' => 'integer|min:1',
            'sort_order' => 'integer',
            // ممكن تنضاف الخيارات مباشرة مع المجموعة بنفس الطلب
            'modifiers' => 'sometimes|array',
            'modifiers.*.name' => 'required_with:modifiers|string|max:255',
            'modifiers.*.extra_price' => 'required_with:modifiers|numeric|min:0',
        ]);

        $group = $menuItem->modifierGroups()->create([
            'name' => $validated['name'],
            'is_required' => $validated['is_required'] ?? false,
            'max_selections' => $validated['max_selections'] ?? 1,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        foreach ($validated['modifiers'] ?? [] as $modifier) {
            $group->modifiers()->create($modifier);
        }

        return response()->json($group->load('modifiers'), 201);
    }

    public function update(Request $request, MenuItem $menuItem, ModifierGroup $modifierGroup)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'is_required' => 'boolean',
            'max_selections' => 'integer|min:1',
            'sort_order' => 'integer',
        ]);

        $modifierGroup->update($validated);

        return response()->json($modifierGroup->load('modifiers'));
    }

    public function destroy(MenuItem $menuItem, ModifierGroup $modifierGroup)
    {
        $modifierGroup->delete();

        return response()->json(['message' => 'تم حذف المجموعة بنجاح']);
    }

    // ---------- إدارة الخيار المفرد جوا المجموعة ----------

    public function storeModifier(Request $request, MenuItem $menuItem, ModifierGroup $modifierGroup)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'extra_price' => 'required|numeric|min:0',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $modifier = $modifierGroup->modifiers()->create($validated);

        return response()->json($modifier, 201);
    }

    public function updateModifier(Request $request, MenuItem $menuItem, ModifierGroup $modifierGroup, Modifier $modifier)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'extra_price' => 'sometimes|required|numeric|min:0',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $modifier->update($validated);

        return response()->json($modifier);
    }

    public function destroyModifier(MenuItem $menuItem, ModifierGroup $modifierGroup, Modifier $modifier)
    {
        $modifier->delete();

        return response()->json(['message' => 'تم حذف الخيار بنجاح']);
    }
}
