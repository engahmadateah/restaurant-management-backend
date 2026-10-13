<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashDrawer;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
  
    public function index(Request $request)
    {
        if ($request->filled('order_id')) {
            return Payment::with('order')->where('order_id', $request->order_id)->latest()->get();
        }

        $request->validate([
            'method' => 'nullable|in:cash,card,online',
            'status' => 'nullable|in:paid,pending,refunded',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'search' => 'nullable|string|max:100',
        ]);

     
        $filtered = function ($query) use ($request) {
            $query->when($request->filled('method'), fn ($q) => $q->where('method', $request->method))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to));

            if ($request->filled('search')) {
                $term = trim($request->search);
                $like = '%' . addcslashes($term, '%_\\') . '%';
                $query->where(function ($q) use ($term, $like) {
                    $q->whereHas('order', fn ($o) => $o->where('order_number', 'like', $like));
                    if (ctype_digit($term)) {
                        $q->orWhere('order_id', (int) $term);
                    }
                });
            }

            return $query;
        };

        $page = $filtered(Payment::with('order:id,order_number,total,payment_status'))
            ->latest()->latest('id')
            ->paginate($this->perPage($request, 25));

        $paid = $filtered(Payment::query())->where('status', 'paid')
            ->groupBy('method')
            ->select('method', DB::raw('SUM(amount) as total'))
            ->pluck('total', 'method');

        $totalsC = [];
        foreach (['cash', 'card', 'online'] as $method) {
            $totalsC[$method] = Money::cents($paid[$method] ?? 0);
        }

        return response()->json($page->toArray() + ['summary' => [
            'paid_total' => Money::fromCents(array_sum($totalsC)),
            'cash_total' => Money::fromCents($totalsC['cash']),
            'card_total' => Money::fromCents($totalsC['card']),
            'online_total' => Money::fromCents($totalsC['online']),
        ]]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'method' => 'required|in:cash,card,online',
            'amount' => 'required|numeric|min:0.01', 
            'tendered_amount' => 'nullable|numeric|min:0.01|max:99999999',
        
            'status' => 'sometimes|in:pending,paid',
        ]);

        $status = $validated['status'] ?? 'paid';

        $payment = DB::transaction(function () use ($validated, $status, $request) {
            $order = Order::whereKey($validated['order_id'])->lockForUpdate()->firstOrFail();

            if ($order->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'order_id' => ['ما بينقدر ينسجّل دفع على طلب ملغي'],
                ]);
            }

            if ($status === 'paid') {
                $this->assertWithinRemaining($order, Money::cents($validated['amount']));
            }

      
            $cash = $status === 'paid'
                ? $this->cashFields($validated['method'], Money::cents($validated['amount']), $validated['tendered_amount'] ?? null)
                : $this->assertNoTendered($validated['tendered_amount'] ?? null);

            $payment = Payment::create([
                'order_id' => $order->id,
                'method' => $validated['method'],
                'amount' => $validated['amount'],
                'status' => $status,
                'received_by' => $request->user()->id,
            ] + $cash);

            $order->syncPaymentStatus();

            return $payment;
        });

        return response()->json($payment->load('order'), 201);
    }

    public function show(Payment $payment)
    {
        return $payment->load('order');
    }

 
    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'method' => 'sometimes|required|in:cash,card,online',
            'amount' => 'sometimes|required|numeric|min:0.01',
            'tendered_amount' => 'sometimes|nullable|numeric|min:0.01|max:99999999',
            'status' => 'sometimes|required|in:paid',
        ]);

        DB::transaction(function () use ($validated, $payment) {
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

       
            if (isset($validated['method']) && $validated['method'] !== $payment->method
                && ($payment->method === 'cash' || $validated['method'] === 'cash')
                && CashDrawer::closedCovers($payment)) {
                throw ValidationException::withMessages([
                    'method' => ['هالدفعة النقدية ضمن صندوق مسكّر، ما بينفع تغيّر طريقتها من أو إلى نقدي.'],
                ]);
            }

    
            $changesMoney = isset($validated['amount']) || isset($validated['status']) || isset($validated['tendered_amount']);

            if ($changesMoney && $payment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['ما بينقدر تعدّل مبلغ أو حالة دفعة مؤكدة. استخدم نظام الاسترجاع.'],
                ]);
            }

            $newAmount = Money::cents($validated['amount'] ?? $payment->amount);

            if (($validated['status'] ?? null) === 'paid') {
                $this->assertWithinRemaining($order, $newAmount);
            }

            $data = Arr::only($validated, ['method', 'amount', 'status']);
            $finalMethod = $validated['method'] ?? $payment->method;
            $confirming = ($validated['status'] ?? null) === 'paid';

       
            if ($payment->status !== 'pending' || $confirming) {
                if ($confirming || isset($validated['method'])) {
                    $data += $this->cashFields($finalMethod, $newAmount, $validated['tendered_amount'] ?? null);
                }
            } elseif (isset($validated['tendered_amount'])) {
       
                $this->assertNoTendered($validated['tendered_amount']);
            }

            $payment->update($data);
            $order->syncPaymentStatus();
        });

        return response()->json($payment->fresh()->load('order'));
    }


    public function destroy(Payment $payment)
    {
        DB::transaction(function () use ($payment) {
            $order = Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['ما بينحذف إلا الدفعات المعلّقة. الدفعات المؤكدة بتنعالج بالاسترجاع.'],
                ]);
            }

            $payment->delete();
            $order->syncPaymentStatus();
        });

        return response()->json(['message' => 'تم حذف الدفعة بنجاح']);
    }

   
    private function cashFields(string $method, int $amountCents, float|int|string|null $tendered): array
    {
        if ($method !== 'cash') {
            return $this->assertNoTendered($tendered);
        }

        $tenderedCents = $tendered !== null ? Money::cents($tendered) : $amountCents;

        if ($tenderedCents < $amountCents) {
            throw ValidationException::withMessages([
                'tendered_amount' => ['المبلغ المقبوض (' . number_format(Money::fromCents($tenderedCents), 2)
                    . ') أقل من مبلغ الدفعة (' . number_format(Money::fromCents($amountCents), 2) . ')'],
            ]);
        }

        return [
            'tendered_amount' => Money::fromCents($tenderedCents),
            'change_amount' => Money::fromCents($tenderedCents - $amountCents),
        ];
    }


    private function assertNoTendered(float|int|string|null $tendered): array
    {
        if ($tendered !== null) {
            throw ValidationException::withMessages([
                'tendered_amount' => ['المبلغ المقبوض والباقي بس للدفعات النقدية المؤكدة'],
            ]);
        }

        return ['tendered_amount' => null, 'change_amount' => null];
    }


    private function assertWithinRemaining(Order $order, int $amountCents): void
    {
        $remaining = Money::cents($order->total) - $order->paymentTotals()['net'];

        if ($amountCents > $remaining) {
            throw ValidationException::withMessages([
                'amount' => ['المبلغ أكبر من المتبقي على الطلب (' . number_format(Money::fromCents(max($remaining, 0)), 2) . ')'],
            ]);
        }
    }
}
