<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Services\SalesReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // ملخص أرقام سريع لصفحة لوحة التحكم الرئيسية
    public function stats(Request $request, SalesReport $report)
    {
        $today = now()->startOfDay();

        $todayOrders = Order::where('created_at', '>=', $today);

        $topItems = OrderItem::select('menu_item_id', DB::raw('SUM(quantity) as total_qty'))
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->groupBy('menu_item_id')
            ->orderByDesc('total_qty')
            ->with('menuItem:id,name')
            ->limit(5)
            ->get();

        // الأرقام المالية بس لمن عنده reports.view (الشيف والسائق بيشوفوا الداشبورد بدون إيرادات)
        $money = [];
        if ($request->user()->hasPermission('reports.view')) {
            // إيرادات آخر 7 أيام (لرسم بياني بالداشبورد) - بنعبّي الأيام الفاضية بصفر
            // حتى المحور الزمني يضل متصل حتى لو ما كان في مبيعات بيوم معيّن.
            // نفس تعريف الإيراد بالتقارير (صافي بدون ضريبة وبعد الاسترجاعات)
            $revenueRows = $report->byDay($today->copy()->subDays(6), now()->endOfDay())
                ->pluck('net_revenue', 'date');

            $todaySummary = $report->summary($today, now()->endOfDay());
            $totalSummary = $report->summary(now()->subYears(50), now()->endOfDay());

            $money = [
                'revenue_by_day' => collect(range(6, 0))->map(function ($i) use ($revenueRows) {
                    $date = now()->subDays($i)->format('Y-m-d');

                    return [
                        'date' => $date,
                        'revenue' => (float) ($revenueRows[$date] ?? 0),
                    ];
                })->values(),
                'revenue_today' => $todaySummary['net_revenue'],
                'gross_sales_today' => $todaySummary['gross_sales'],
                'revenue_total' => $totalSummary['net_revenue'],
            ];
        }

        $ordersByStatus = Order::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json($money + [
            'orders_by_status' => $ordersByStatus,
            'categories_count' => Category::count(),
            'menu_items_count' => MenuItem::count(),
            'tables_count' => RestaurantTable::count(),
            'tables_occupied' => RestaurantTable::where('status', 'occupied')->count(),
            'orders_total' => Order::count(),
            'orders_pending' => Order::where('status', 'pending')->count(),
            'orders_preparing' => Order::where('status', 'preparing')->count(),
            'orders_today_count' => (clone $todayOrders)->count(),
            'top_items' => $topItems->map(fn ($row) => [
                'name' => $row->menuItem?->name,
                'quantity' => (int) $row->total_qty,
            ]),
            // قبل: كان يحسب delivery_status != delivered فيدخل فيه الطلبات الملغية
            'deliveries_active' => Order::where('order_type', 'delivery')
                ->where('status', '!=', 'cancelled')
                ->whereIn('delivery_status', ['pending', 'assigned', 'out_for_delivery'])
                ->count(),
            'low_stock_items' => MenuItem::where('track_stock', true)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->count(),
        ]);
    }
}
