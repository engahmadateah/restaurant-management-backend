<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payroll;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;


class SalesReport
{
    
    private function aggregates(): array
    {
        return [
            DB::raw('COUNT(*) as orders_count'),
            DB::raw('COALESCE(SUM(orders.total), 0) as gross_sales'),
            DB::raw('COALESCE(SUM(orders.tax), 0) as tax'),
            DB::raw('COALESCE(SUM(rf.refunded), 0) as refunds'),
            DB::raw('COALESCE(SUM(pm.paid), 0) - COALESCE(SUM(rf.refunded), 0) as collected'),
        ];
    }


    private function orders(CarbonInterface $from, CarbonInterface $to): Builder
    {
        $refunds = DB::table('refunds')
            ->where('status', 'approved')
            ->groupBy('order_id')
            ->select('order_id', DB::raw('SUM(amount) as refunded'));

        $payments = DB::table('payments')
            ->whereIn('status', ['paid', 'refunded'])
            ->groupBy('order_id')
            ->select('order_id', DB::raw('SUM(amount) as paid'));

        return DB::table('orders')
            ->leftJoinSub($refunds, 'rf', 'rf.order_id', '=', 'orders.id')
            ->leftJoinSub($payments, 'pm', 'pm.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', 'cancelled');
    }


    private function shape(object $row): array
    {
        $count = (int) $row->orders_count;
        $gross = (float) $row->gross_sales;
        $tax = (float) $row->tax;
        $refunds = (float) $row->refunds;
        $collected = (float) $row->collected;
        $net = $gross - $refunds - $tax;

        return [
            'orders_count' => $count,
            'gross_sales' => round($gross, 2),
            'tax' => round($tax, 2),
            'refunds' => round($refunds, 2),
            'net_revenue' => round($net, 2),
            'revenue' => round($net, 2), 
            'collected' => round($collected, 2),
            'outstanding' => round(max($gross - $refunds - $collected, 0), 2),
            'average_order_value' => $count > 0 ? round($net / $count, 2) : 0.0,
        ];
    }

    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $row = $this->orders($from, $to)->select($this->aggregates())->first();

        return $this->shape($row);
    }

    /** @return Collection<int, array> */
    public function byDay(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->orders($from, $to)
            ->select(array_merge([DB::raw('DATE(orders.created_at) as date')], $this->aggregates()))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => ['date' => (string) $row->date] + $this->shape($row));
    }

    /** @return Collection<int, array> */
    public function byOrderType(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->orders($from, $to)
            ->select(array_merge(['orders.order_type'], $this->aggregates()))
            ->groupBy('orders.order_type')
            ->get()
            ->map(fn ($row) => ['order_type' => $row->order_type] + $this->shape($row));
    }

    /** @return Collection<int, array> */
    public function byStaff(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->orders($from, $to)
            ->whereNotNull('orders.user_id')
            ->select(array_merge(['orders.user_id'], $this->aggregates()))
            ->groupBy('orders.user_id')
            ->orderByDesc('gross_sales')
            ->get()
            ->map(fn ($row) => ['user_id' => (int) $row->user_id] + $this->shape($row));
    }

   
    private function itemLines(CarbonInterface $from, CarbonInterface $to): Builder
    {
        $extras = DB::table('order_item_modifier')
            ->groupBy('order_item_id')
            ->select('order_item_id', DB::raw('SUM(extra_price) as extra'));

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoinSub($extras, 'om', 'om.order_item_id', '=', 'order_items.id')
            ->join('menu_items', 'menu_items.id', '=', 'order_items.menu_item_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->where('orders.status', '!=', 'cancelled');
    }

    private function lineRevenueSql(): string
    {
        return 'SUM((order_items.price + COALESCE(om.extra, 0)) * order_items.quantity'
            . ' * COALESCE(1 - orders.discount * 1.0 / NULLIF(orders.subtotal, 0), 1))';
    }

    /** @return Collection<int, array> */
    public function topItems(CarbonInterface $from, CarbonInterface $to, int $limit): Collection
    {
        return $this->itemLines($from, $to)
            ->select(
                'order_items.menu_item_id',
                'menu_items.name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw($this->lineRevenueSql() . ' as total_revenue')
            )
            ->groupBy('order_items.menu_item_id', 'menu_items.name')
            ->orderByDesc('total_quantity')
            ->orderByDesc('total_revenue')
            ->orderBy('order_items.menu_item_id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'menu_item_id' => (int) $row->menu_item_id,
                'name' => $row->name,
                'quantity_sold' => (int) $row->total_quantity,
                'revenue' => round((float) $row->total_revenue, 2),
            ]);
    }

    /** @return Collection<int, array> */
    public function revenueByCategory(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->itemLines($from, $to)
            ->join('categories', 'categories.id', '=', 'menu_items.category_id')
            ->select(
                'categories.id as category_id',
                'categories.name as category_name',
                DB::raw($this->lineRevenueSql() . ' as revenue'),
                DB::raw('SUM(order_items.quantity) as quantity_sold')
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($row) => [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'revenue' => round((float) $row->revenue, 2),
                'quantity_sold' => (int) $row->quantity_sold,
            ]);
    }

  
    public function profit(CarbonInterface $from, CarbonInterface $to): array
    {
        $summary = $this->summary($from, $to);

        $expenseQuery = fn () => Expense::whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]);

        $expenses = round((float) $expenseQuery()->sum('amount'), 2);

        $expensesByCategory = $expenseQuery()
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->get();

      
        $payroll = round((float) Payroll::whereIn('status', ['approved', 'paid'])
            ->whereBetween('period_end', [$from->toDateString(), $to->toDateString()])
            ->sum('gross_pay'), 2);

        $totalCosts = round($expenses + $payroll, 2);

        return [
            'revenue' => $summary['net_revenue'],
            'net_revenue' => $summary['net_revenue'],
            'gross_sales' => $summary['gross_sales'],
            'tax' => $summary['tax'],
            'refunds' => $summary['refunds'],
            'expenses' => $expenses,
            'payroll' => $payroll,
            'total_costs' => $totalCosts,
            'net_profit' => round($summary['net_revenue'] - $totalCosts, 2),
            'expenses_by_category' => $expensesByCategory,
        ];
    }
}
