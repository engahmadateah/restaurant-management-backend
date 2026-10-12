<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    public function index()
    {
        return Coupon::latest()->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:coupons,code',
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $this->assertValidPercentage($validated['type'], $validated['value']);

        $coupon = Coupon::create($validated);

        return response()->json($coupon, 201);
    }

    public function show(Coupon $coupon)
    {
        return $coupon;
    }

    public function update(Request $request, Coupon $coupon)
    {
        $validated = $request->validate([
            'code' => 'sometimes|required|string|unique:coupons,code,' . $coupon->id,
            'type' => 'sometimes|required|in:percentage,fixed',
            'value' => 'sometimes|required|numeric|min:0',
            'expires_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        // النوع والقيمة الفعليين بعد التعديل (ممكن واحد منهم بس يكون بالطلب)
        $this->assertValidPercentage(
            $validated['type'] ?? $coupon->type,
            $validated['value'] ?? $coupon->value
        );

        $coupon->update($validated);

        return response()->json($coupon);
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }

    // دالة خاصة نستخدمها لاحقًا للتحقق من صلاحية كوبون قبل تطبيقه
    /** الكوبون النسبي ما بيتجاوز 100% (قبل كان 150% يعطي طلب مجاني) */
    private function assertValidPercentage(string $type, float|int|string $value): void
    {
        if ($type === 'percentage' && (float) $value > 100) {
            throw ValidationException::withMessages([
                'value' => ['نسبة الخصم ما بتتجاوز 100%'],
            ]);
        }
    }

    public function validateCoupon(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $coupon = Coupon::where('code', $request->code)
            ->where('is_active', true)
            ->first();

        if (! $coupon) {
            return response()->json(['message' => 'كوبون غير صالح'], 404);
        }

        if ($coupon->expires_at && $coupon->expires_at->copy()->endOfDay()->isPast()) {
            return response()->json(['message' => 'انتهت صلاحية الكوبون'], 400);
        }

        return response()->json($coupon);
    }
}