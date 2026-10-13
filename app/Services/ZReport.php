<?php

namespace App\Services;

use App\Models\CashDrawer;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;


class ZReport
{
    public function __construct(private SalesReport $sales)
    {
    }

    public function forDay(CarbonInterface $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        $sales = $this->sales->summary($from, $to);

        $orderTotals = DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(discount), 0) as discounts, COALESCE(SUM(delivery_fee), 0) as delivery_fees')
            ->first();

        $cancelled = DB::table('orders')
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'cancelled')
            ->count();

        $payments = Payment::query()
            ->whereIn('status', ['paid', 'refunded'])
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('method')
            ->select('method', DB::raw('COUNT(*) as c'), DB::raw('SUM(amount) as total'), DB::raw('COALESCE(SUM(change_amount), 0) as change_given'))
            ->get();

        $byMethod = [];
        $collectedC = 0;
        foreach (['cash', 'card', 'online'] as $method) {
            $row = $payments->firstWhere('method', $method);
            $totalC = Money::cents($row->total ?? 0);
            $collectedC += $totalC;
            $byMethod[] = [
                'method' => $method,
                'count' => (int) ($row->c ?? 0),
                'total' => Money::fromCents($totalC),
                'change_given' => round((float) ($row->change_given ?? 0), 2),
            ];
        }

  
        $refundsQuery = fn () => Refund::query()->where('status', 'approved')->whereBetween('processed_at', [$from, $to]);
        $refundsTotalC = Money::cents($refundsQuery()->sum('amount'));
        $cashRefundsC = Money::cents(
            $refundsQuery()->whereHas('payment', fn ($q) => $q->where('method', 'cash'))->sum('amount')
        );

    
        $drawers = CashDrawer::with('user:id,name')
            ->whereBetween('closed_at', [$from, $to])
            ->orderBy('closed_at')
            ->get();

        $shortageC = 0;
        $overageC = 0;
        foreach ($drawers as $d) {
            $diffC = Money::cents($d->difference);
            $diffC < 0 ? $shortageC += $diffC : $overageC += $diffC;
        }

 
        $movementRows = CashMovement::query()
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('type')
            ->select('type', DB::raw('COUNT(*) as c'), DB::raw('SUM(amount) as total'))
            ->get()
            ->keyBy('type');
        $movementTotal = fn (string $type) => Money::fromCents(Money::cents($movementRows[$type]->total ?? 0));

        return [
            'date' => $from->toDateString(),
            'sales' => [
                'orders_count' => $sales['orders_count'],
                'gross_sales' => $sales['gross_sales'],
                'discounts' => round((float) $orderTotals->discounts, 2),
                'delivery_fees' => round((float) $orderTotals->delivery_fees, 2),
                'tax' => $sales['tax'],
                'outstanding' => $sales['outstanding'],
                'cancelled_orders' => $cancelled,
                'by_order_type' => $this->sales->byOrderType($from, $to)->values()->all(),
            ],
            'payments' => [
                'by_method' => $byMethod,
                'collected' => Money::fromCents($collectedC),
            ],
            'refunds' => [
                'count' => $refundsQuery()->count(),
                'total' => Money::fromCents($refundsTotalC),
                'cash_total' => Money::fromCents($cashRefundsC),
            ],
            'cash_movements' => [
                'count' => (int) $movementRows->sum('c'),
                'expenses' => $movementTotal(CashMovement::TYPE_EXPENSE),
                'withdrawals' => $movementTotal(CashMovement::TYPE_WITHDRAWAL),
                'deposits' => $movementTotal(CashMovement::TYPE_DEPOSIT),
            ],
        
            'net_collected' => Money::fromCents($collectedC - $refundsTotalC),
            'drawers' => [
                'count' => $drawers->count(),
                'open_now' => CashDrawer::whereNull('closed_at')->count(),
                'shortage' => Money::fromCents($shortageC), 
                'overage' => Money::fromCents($overageC),
                'net_difference' => Money::fromCents($shortageC + $overageC),
                'items' => $drawers->map(fn (CashDrawer $d) => [
                    'id' => $d->id,
                    'user' => $d->user?->name,
                    'opened_at' => $d->opened_at->toIso8601String(),
                    'closed_at' => $d->closed_at->toIso8601String(),
                    'opening_float' => (float) $d->opening_float,
                    'cash_sales' => (float) $d->cash_sales,
                    'cash_refunds' => (float) $d->cash_refunds,
                    'cash_expenses' => (float) $d->cash_expenses,
                    'cash_withdrawals' => (float) $d->cash_withdrawals,
                    'cash_deposits' => (float) $d->cash_deposits,
                    'expected_cash' => (float) $d->expected_cash,
                    'counted_cash' => (float) $d->counted_cash,
                    'difference' => (float) $d->difference,
                ])->all(),
            ],
        ];
    }
}
