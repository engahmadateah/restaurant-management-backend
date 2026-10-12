<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CurrencyController extends Controller
{
    // متاحة لأي مستخدم مسجل دخول (الفرونت بيحتاجها يعبي قائمة اختيار العملة)
    public function index(Request $request)
    {
        $query = Currency::query();

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return $query->orderByDesc('is_default')->orderBy('code')->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:8|unique:currencies,code',
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:8',
            'exchange_rate' => 'required|numeric|min:0.000001',
            'is_active' => 'boolean',
        ]);

        // أول عملة تنضاف تصير هي الافتراضية تلقائيًا
        $validated['is_default'] = ! Currency::query()->exists();

        $currency = Currency::create($validated);

        return response()->json($currency, 201);
    }

    public function update(Request $request, Currency $currency)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'symbol' => 'sometimes|required|string|max:8',
            'exchange_rate' => 'sometimes|required|numeric|min:0.000001',
            'is_active' => 'boolean',
        ]);

        $currency->update($validated);

        return response()->json($currency);
    }

    public function destroy(Currency $currency)
    {
        if ($currency->is_default) {
            return response()->json(['message' => 'ما فيك تحذف العملة الأساسية، عيّن غيرها أساسية أول'], 422);
        }

        $currency->delete();

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }

    // تعيين عملة معيّنة كأساسية (كل الأسعار بالنظام محسوبة عليها،
    // exchange_rate تبعها تلقائيًا بيصير 1)
    public function setDefault(Currency $currency)
    {
        DB::transaction(function () use ($currency) {
            Currency::where('is_default', true)->update(['is_default' => false]);
            $currency->update(['is_default' => true, 'exchange_rate' => 1]);
        });

        return response()->json($currency->fresh());
    }
}
