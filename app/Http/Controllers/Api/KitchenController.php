<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Order;
use Illuminate\Validation\ValidationException;


class KitchenController extends Controller
{
 
    public function queue()
    {
        return Order::with(['items.menuItem', 'table'])
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->orderBy('created_at')
            ->get();
    }

    public function start(Order $order)
    {
        if ($order->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['ما بينقدر يبدأ تحضير طلب حالته "' . $order->status . '"'],
            ]);
        }

        $order->update([
            'status' => 'preparing',
            'preparing_at' => now(),
        ]);

        return response()->json($order->fresh()->load(['items.menuItem', 'table']));
    }

    public function ready(Order $order)
    {
        if ($order->status !== 'preparing') {
            throw ValidationException::withMessages([
                'status' => ['ما بينقدر يصير جاهز طلب حالته "' . $order->status . '"'],
            ]);
        }

        $order->update([
            'status' => 'ready',
            'ready_at' => now(),
        ]);

     
        if ($order->order_type === 'delivery' && $order->delivery_status === 'pending') {
            AppNotification::notifyRole(
                'admin',
                'order_status',
                'طلب جاهز للتوصيل',
                'الطلب رقم ' . $order->order_number . ' جاهز، بحاجة لتعيين سائق',
                ['order_id' => $order->id]
            );
        }

        return response()->json($order->fresh()->load(['items.menuItem', 'table']));
    }


    public function serve(Order $order)
    {
        if ($order->status !== 'ready') {
            throw ValidationException::withMessages([
                'status' => ['ما بينقدر يتسلّم طلب حالته "' . $order->status . '"'],
            ]);
        }

        if ($order->order_type === 'delivery') {
            throw ValidationException::withMessages([
                'order_type' => ['طلبات التوصيل بتتسكر من مسار التوصيل (تعيين سائق ثم تسليم)'],
            ]);
        }

        $order->markCompleted();

        return response()->json($order->fresh()->load(['items.menuItem', 'table', 'customer']));
    }
}
