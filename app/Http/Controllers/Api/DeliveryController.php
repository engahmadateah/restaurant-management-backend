<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إدارة طلبات التوصيل: تعيين سائق ومتابعة حالة التسليم
 * pending -> assigned -> out_for_delivery -> delivered
 */
class DeliveryController extends Controller
{
    // مين بيدير كل التوصيلات؟ يلي معه orders.manage (كاشير/مدير/أدمن). غيره (سائق)
    // بيشوف ويحدّث الطلبات المسندة إله هو بس.
    private function managesAllDeliveries(Request $request): bool
    {
        return $request->user()->hasPermission('orders.manage');
    }

    // كل طلبات التوصيل النشطة (يلي لسا ما توصلت ولا انلغت)
    public function index(Request $request)
    {
        $query = Order::with(['items.menuItem', 'customer', 'driver'])
            ->where('order_type', 'delivery')
            ->where('delivery_status', '!=', 'delivered')
            ->where('status', '!=', 'cancelled');

        if (! $this->managesAllDeliveries($request)) {
            $query->where('driver_id', $request->user()->id);
        }

        return $query->orderBy('created_at')->get();
    }

    public function assignDriver(Request $request, Order $order)
    {
        if ($order->order_type !== 'delivery') {
            throw ValidationException::withMessages([
                'order_type' => ['هاد الطلب مش طلب توصيل'],
            ]);
        }

        $validated = $request->validate([
            'driver_id' => 'required|exists:users,id',
        ]);

        $driver = User::findOrFail($validated['driver_id']);
        if ($driver->role !== 'driver' || ! $driver->is_active) {
            throw ValidationException::withMessages([
                'driver_id' => ['هاد المستخدم مش سائق فعّال'],
            ]);
        }

        DB::transaction(function () use ($order, $driver) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // ما بينعيّن/ينبدّل سائق لطلب ملغي أو مكتمل أو خرج للتوصيل فعلاً
            if (in_array($order->status, ['completed', 'cancelled'], true)
                || ! in_array($order->delivery_status, ['pending', 'assigned'], true)) {
                throw ValidationException::withMessages([
                    'order' => ['ما بينقدر ينعيّن سائق لهاد الطلب بحالته الحالية'],
                ]);
            }

            $order->update([
                'driver_id' => $driver->id,
                'delivery_status' => 'assigned',
            ]);
        });

        AppNotification::notifyUser(
            $driver->id,
            'order_status',
            'طلب توصيل جديد',
            'انعيّنت لطلب رقم ' . $order->order_number,
            ['order_id' => $order->id]
        );

        return response()->json($order->fresh()->load(['items.menuItem', 'customer', 'driver']));
    }

    public function updateStatus(Request $request, Order $order)
    {
        if ($order->order_type !== 'delivery') {
            throw ValidationException::withMessages([
                'order_type' => ['هاد الطلب مش طلب توصيل'],
            ]);
        }

        // السائق بيحدّث حالة طلباته هو بس (بدون هاد الفحص أي سائق كان يسكّر طلب غيره)
        if (! $this->managesAllDeliveries($request) && $order->driver_id !== $request->user()->id) {
            return response()->json(['message' => 'هاد الطلب مش مسند إلك'], 403);
        }

        $validated = $request->validate([
            'delivery_status' => 'required|in:out_for_delivery,delivered',
        ]);

        $validTransitions = [
            'assigned' => 'out_for_delivery',
            'out_for_delivery' => 'delivered',
        ];

        DB::transaction(function () use ($order, $validated, $validTransitions) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // طلب ملغي/مكتمل ما بيتحرك. markCompleted() كان رح يقلب الملغي لمكتمل.
            if (in_array($order->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['ما بينقدر يتحدّث توصيل طلب حالته "' . $order->status . '"'],
                ]);
            }

            if (($validTransitions[$order->delivery_status] ?? null) !== $validated['delivery_status']) {
                throw ValidationException::withMessages([
                    'delivery_status' => ['ما بينقدر ينتقل من "' . $order->delivery_status . '" لـ "' . $validated['delivery_status'] . '"'],
                ]);
            }

            // الطلب لازم يكون جاهز من المطبخ قبل ما يطلع للتوصيل
            if ($validated['delivery_status'] === 'out_for_delivery' && $order->status !== 'ready') {
                throw ValidationException::withMessages([
                    'status' => ['الطلب لسا مش جاهز من المطبخ'],
                ]);
            }

            $order->update(['delivery_status' => $validated['delivery_status']]);

            if ($validated['delivery_status'] === 'delivered') {
                $order->markCompleted();
            }
        });

        return response()->json($order->fresh()->load(['items.menuItem', 'customer', 'driver']));
    }
}
