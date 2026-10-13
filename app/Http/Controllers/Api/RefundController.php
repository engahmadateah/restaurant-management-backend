<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;


class RefundController extends Controller
{
    public function index(Request $request)
    {
        $query = Refund::with(['order', 'payment', 'requestedBy', 'processedBy']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        return $query->latest()->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_id' => 'nullable|exists:payments,id',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        $refund = DB::transaction(function () use ($validated, $request) {
            
            $order = Order::whereKey($validated['order_id'])->lockForUpdate()->firstOrFail();
            $amount = Money::cents($validated['amount']);

 
            $t = $order->paymentTotals();
            $available = $t['gross'] - $t['approved'] - $t['pending'];

            if ($amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => ['المبلغ المطلوب أكبر من المتاح للاسترجاع (' . number_format(Money::fromCents(max($available, 0)), 2) . ')'],
                ]);
            }

        
            if (! empty($validated['payment_id'])) {
                $payment = Payment::whereKey($validated['payment_id'])->where('order_id', $order->id)->first();

                if (! $payment || ! in_array($payment->status, ['paid', 'refunded'], true)) {
                    throw ValidationException::withMessages([
                        'payment_id' => ['الدفعة غير موجودة على هاد الطلب أو غير مؤكدة'],
                    ]);
                }

                $paymentLeft = Money::cents($payment->amount)
                    - Money::cents($payment->refunds()->whereIn('status', ['approved', 'pending'])->sum('amount'));

                if ($amount > $paymentLeft) {
                    throw ValidationException::withMessages([
                        'amount' => ['المبلغ أكبر من المتبقي من هاي الدفعة (' . number_format(Money::fromCents(max($paymentLeft, 0)), 2) . ')'],
                    ]);
                }
            }

            $refund = Refund::create([
                'order_id' => $order->id,
                'payment_id' => $validated['payment_id'] ?? null,
                'amount' => $validated['amount'],
                'reason' => $validated['reason'],
                'status' => 'pending',
                'requested_by' => $request->user()->id,
            ]);

            AppNotification::notifyRole(
                'admin',
                'refund_request',
                'طلب استرجاع جديد',
                'طلب استرجاع بقيمة ' . $refund->amount . ' على الطلب ' . $order->order_number,
                ['order_id' => $order->id]
            );

            return $refund;
        });

        return response()->json($refund->load(['order', 'payment', 'requestedBy']), 201);
    }

    public function show(Refund $refund)
    {
        return $refund->load(['order', 'payment', 'requestedBy', 'processedBy']);
    }

   
    public function approve(Request $request, Refund $refund)
    {
        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:500',
        ]);

        $refund = DB::transaction(function () use ($validated, $request, $refund) {
            $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();
           
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($refund->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['هاد الطلب متعالج مسبقًا (' . $refund->status . ')'],
                ]);
            }

           
            $t = $order->paymentTotals();
            if (Money::cents($refund->amount) > $t['net']) {
                throw ValidationException::withMessages([
                    'amount' => ['مبلغ الاسترجاع أكبر من الصافي المدفوع على الطلب (' . number_format(Money::fromCents(max($t['net'], 0)), 2) . ')'],
                ]);
            }

            $refund->update([
                'status' => 'approved',
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
                'admin_note' => $validated['admin_note'] ?? null,
            ]);

            
            if ($refund->payment_id) {
                $payment = Payment::find($refund->payment_id);
                if ($payment) {
                    $refundedOnPayment = Money::cents($payment->refunds()->where('status', 'approved')->sum('amount'));
                    if ($refundedOnPayment >= Money::cents($payment->amount)) {
                        $payment->update(['status' => 'refunded']);
                    }
                }
            }

            $order->syncPaymentStatus();

            AppNotification::notifyUser(
                $refund->requested_by,
                'refund_status',
                'تمت الموافقة على الاسترجاع',
                'تمت الموافقة على استرجاع ' . $refund->amount . ' للطلب ' . $order->order_number,
                ['order_id' => $order->id]
            );

            return $refund;
        });

        return response()->json($refund->fresh()->load(['order', 'payment', 'processedBy']));
    }

   
    public function reject(Request $request, Refund $refund)
    {
        if ($refund->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['هاد الطلب متعالج مسبقًا (' . $refund->status . ')'],
            ]);
        }

        $validated = $request->validate([
            'admin_note' => 'nullable|string|max:500',
        ]);

        $refund->update([
            'status' => 'rejected',
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
            'admin_note' => $validated['admin_note'] ?? null,
        ]);

        AppNotification::notifyUser(
            $refund->requested_by,
            'refund_status',
            'تم رفض طلب الاسترجاع',
            'تم رفض طلب استرجاع الطلب ' . $refund->order->order_number . ($validated['admin_note'] ?? '' ? (' - ' . $validated['admin_note']) : ''),
            ['order_id' => $refund->order_id]
        );

        return response()->json($refund->fresh()->load(['order', 'processedBy']));
    }
}
