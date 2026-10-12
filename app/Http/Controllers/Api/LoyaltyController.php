<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    // رصيد النقاط الحالي + سجل الحركات لزبون معيّن
    public function show(Customer $customer)
    {
        return response()->json([
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'loyalty_points' => $customer->loyalty_points,
            'transactions' => $customer->loyaltyTransactions()->latest()->get(),
        ]);
    }

    // تعديل يدوي من الأدمن (زيادة أو خصم نقاط، مثلاً كتعويض أو مكافأة خاصة)
    public function adjust(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            // موجب = إضافة، سالب = خصم
            'points' => 'required|integer|not_in:0',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validated['points'] > 0) {
            $customer->addLoyaltyPoints($validated['points'], 'adjust', $validated['description'] ?? 'تعديل يدوي من الأدمن');
        } else {
            $customer->redeemLoyaltyPoints(abs($validated['points']), $validated['description'] ?? 'تعديل يدوي من الأدمن', null, 'adjust');
        }

        return response()->json([
            'customer_id' => $customer->id,
            'loyalty_points' => $customer->fresh()->loyalty_points,
        ]);
    }
}
