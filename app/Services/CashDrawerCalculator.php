<?php

namespace App\Services;

use App\Models\CashDrawer;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;


class CashDrawerCalculator
{
    /**
     * @return array{cash_sales: float, cash_refunds: float, cash_expenses: float, cash_withdrawals: float, cash_deposits: float, expected_cash: float, by_method: array<string, array{count: int, total: float}>}
     */
    public function summarize(CashDrawer $drawer, ?CarbonInterface $until = null): array
    {
        $until ??= $drawer->closed_at ?? now();

        $byMethod = Payment::query()
            ->where('received_by', $drawer->user_id)
            ->whereIn('status', ['paid', 'refunded'])
            ->whereBetween('created_at', [$drawer->opened_at, $until])
            ->groupBy('method')
            ->select('method', DB::raw('COUNT(*) as c'), DB::raw('SUM(amount) as total'))
            ->get()
            ->mapWithKeys(fn ($r) => [$r->method => ['count' => (int) $r->c, 'total' => round((float) $r->total, 2)]])
            ->all();

        $cashSalesC = Money::cents($byMethod['cash']['total'] ?? 0);

        $cashRefundsC = Money::cents(
            Refund::query()
                ->where('status', 'approved')
                ->where('requested_by', $drawer->user_id)
                ->whereBetween('processed_at', [$drawer->opened_at, $until])
                ->whereHas('payment', fn ($q) => $q->where('method', 'cash'))
                ->sum('amount')
        );

        $movements = $this->movementTotals($drawer);

        $expectedC = Money::cents($drawer->opening_float) + $cashSalesC - $cashRefundsC
            - Money::cents($movements['expenses'])
            - Money::cents($movements['withdrawals'])
            + Money::cents($movements['deposits']);

        return [
            'cash_sales' => Money::fromCents($cashSalesC),
            'cash_refunds' => Money::fromCents($cashRefundsC),
            'cash_expenses' => $movements['expenses'],
            'cash_withdrawals' => $movements['withdrawals'],
            'cash_deposits' => $movements['deposits'],
            'expected_cash' => Money::fromCents($expectedC),
            'by_method' => $byMethod,
        ];
    }

    /**
   
     *
     * @return array{expenses: float, withdrawals: float, deposits: float}
     */
    public function movementTotals(CashDrawer $drawer): array
    {
        $totals = CashMovement::query()
            ->where('cash_drawer_id', $drawer->id)
            ->groupBy('type')
            ->select('type', DB::raw('SUM(amount) as total'))
            ->pluck('total', 'type');

        return [
            'expenses' => round((float) ($totals[CashMovement::TYPE_EXPENSE] ?? 0), 2),
            'withdrawals' => round((float) ($totals[CashMovement::TYPE_WITHDRAWAL] ?? 0), 2),
            'deposits' => round((float) ($totals[CashMovement::TYPE_DEPOSIT] ?? 0), 2),
        ];
    }
}
