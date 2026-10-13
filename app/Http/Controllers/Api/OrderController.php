<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
   
    public function index(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:pending,preparing,ready,completed,cancelled',
            'order_type' => 'nullable|in:dine_in,delivery,takeaway',
            'payment_status' => 'nullable|in:unpaid,partial,paid,refunded,outstanding',
           
            'source' => 'nullable|in:pos,qr,online',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'search' => 'nullable|string|max:100',
        ]);

 
        $filtered = function ($query, bool $withStatus = true) use ($request) {
            $query->when($withStatus && $request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('order_type'), fn ($q) => $q->where('order_type', $request->order_type))
                ->when($request->filled('source'), fn ($q) => $q->where('source', $request->source))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to));

            if ($request->filled('payment_status')) {
                $request->payment_status === 'outstanding'
                    ? $query->whereIn('payment_status', ['unpaid', 'partial'])
                    : $query->where('payment_status', $request->payment_status);
            }

            if ($request->filled('search')) {
                $like = '%' . addcslashes(trim($request->search), '%_\\') . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('order_number', 'like', $like)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                        ->orWhereHas('table', fn ($t) => $t->where('table_number', 'like', $like));
                });
            }

            return $query;
        };

        $query = $filtered(Order::with(['items.menuItem', 'items.modifiers', 'table', 'customer', 'user', 'driver']));

        // per_page=all بيرجّع القائمة بدون تقسيم لكن بسقف ALL_ROWS_CAP صف
        if ($this->wantsAll($request)) {
            return $query->latest()->limit(self::ALL_ROWS_CAP)->get();
        }

        // سقف 100 حتى per_page=100000 ما يسحب الجدول كله بطلب واحد؛ id كتعادل لثبات الترتيب بين الصفحات
        $page = $query->latest()->latest('id')->paginate($this->perPage($request));

        $counts = $filtered(Order::query(), false)
            ->groupBy('status')
            ->select('status', DB::raw('COUNT(*) as c'))
            ->pluck('c', 'status');

        $money = $filtered(Order::query())->where('status', '!=', 'cancelled');
        $revenueC = Money::cents((clone $money)->sum('total'));
        $unpaid = (clone $money)->whereIn('payment_status', ['unpaid', 'partial'])->count();

        return response()->json(array_merge($page->toArray(), [
            'summary' => [
                'counts' => (object) $counts->map(fn ($c) => (int) $c)->all(),
                'revenue' => Money::fromCents($revenueC),
                'unpaid' => $unpaid,
            ],
        ]));
    }

    public function store(Request $request, OrderService $orderService)
    {
        $validated = $request->validate([
            'table_id' => 'nullable|exists:restaurant_tables,id',
            'customer_id' => 'nullable|exists:customers,id',
            'coupon_code' => 'nullable|string',
            'order_type' => 'required|in:dine_in,delivery,takeaway',
            'delivery_address' => 'required_if:order_type,delivery|nullable|string|max:255',
            'delivery_phone' => 'nullable|string|max:30',
            // كم نقطة ولاء بدو الزبون يستبدلها كخصم على هاد الطلب (اختياري)
            'redeem_points' => 'nullable|integer|min:1',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => ['required', Rule::exists('menu_items', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
            // معرّفات الخيارات الإضافية المختارة (Modifiers) لهاد الصنف بالطلب - اختياري
            'items.*.modifiers' => 'sometimes|array',
            'items.*.modifiers.*' => 'integer|exists:modifiers,id',
        ]);

        $order = $orderService->create($validated, $request->user()->id);

        return response()->json($order->load(['items.menuItem', 'items.modifiers', 'table', 'customer']), 201);
    }

    public function show(Order $order)
    {
        return $order->load(['items.menuItem', 'items.modifiers', 'table', 'customer', 'user', 'driver', 'payments', 'loyaltyTransactions', 'refunds']);
    }

    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'sometimes|required|in:pending,preparing,ready,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        $oldStatus = $order->status;

        $order = DB::transaction(function () use ($validated, $order) {
            // قفل الطلب حتى تغييرين متزامنين للحالة ما يتعارضوا
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $newStatus = $validated['status'] ?? null;

            if ($newStatus !== null && $newStatus !== $order->status) {
                // الحالات النهائية (completed/cancelled) ما بترجع لورا، والانتقالات
                // محصورة بـ Order::STATUS_TRANSITIONS
                if (! $order->canTransitionTo($newStatus)) {
                    throw ValidationException::withMessages([
                        'status' => ['ما بينقدر ينتقل الطلب من "' . $order->status . '" لـ "' . $newStatus . '"'],
                    ]);
                }

                // طلبات التوصيل بتتسكر من مسار التوصيل (سائق ثم تسليم) مثل ما بيعمل
                // KitchenController::serve، وإلا بيضل delivery_status معلّق بعد ما الطلب "مكتمل"
                if ($newStatus === 'completed'
                    && $order->order_type === 'delivery'
                    && $order->delivery_status !== 'delivered') {
                    throw ValidationException::withMessages([
                        'status' => ['طلبات التوصيل بتتسكر من مسار التوصيل (تعيين سائق ثم تسليم)'],
                    ]);
                }

                if ($newStatus === 'completed') {
                    // markCompleted(): تحرير الطاولة + نقاط الولاء مرة وحدة بنفس منطق المطبخ/التوصيل
                    $order->markCompleted();
                } elseif ($newStatus === 'cancelled') {
                    // cancel(): ترجيع المخزون ونقاط الولاء وتحرير الطاولة (وبيرفض لو فيه مدفوع)
                    $order->cancel();
                    $order = $order->fresh();
                } else {
                    $timestampColumn = $newStatus === 'preparing' ? 'preparing_at' : 'ready_at';
                    $order->update(['status' => $newStatus, $timestampColumn => now()]);
                }
            }

            if (array_key_exists('notes', $validated)) {
                $order->update(['notes' => $validated['notes']]);
            }

            return $order;
        });

        if ($order->status !== $oldStatus) {
            $statusLabels = [
                'pending' => 'قيد الانتظار',
                'preparing' => 'قيد التحضير',
                'ready' => 'جاهز',
                'completed' => 'مكتمل',
                'cancelled' => 'ملغي',
            ];

            AppNotification::notifyUser(
                $order->user_id,
                'order_status',
                'تحديث حالة الطلب',
                'الطلب رقم ' . $order->order_number . ' أصبح: ' . ($statusLabels[$order->status] ?? $order->status),
                ['order_id' => $order->id]
            );
        }

        return response()->json($order->fresh()->load(['items.menuItem', 'table', 'customer', 'driver']));
    }

    /**
     * الحذف النهائي محصور بصلاحية orders.delete (شوف routes/api.php) ومرفوض لأي طلب
     * إله دفعات أو استرجاعات (سجل مالي). الطلب المفتوح بيمر بـ cancel() أول حتى
     * يرجع المخزون والنقاط، والطلب المكتمل ما بينحذف.
     */
    public function destroy(Order $order)
    {
        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->payments()->exists() || $order->refunds()->exists()) {
                throw ValidationException::withMessages([
                    'order' => ['ما بينحذف طلب إله دفعات أو استرجاعات (سجل مالي). استخدم الإلغاء.'],
                ]);
            }

            if ($order->status !== 'cancelled') {
                $order->cancel(); // بيرفض الطلب المكتمل برسالة واضحة
            }

            $order->delete();
        });

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }
}
