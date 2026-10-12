<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * منطق إنشاء الطلب (مخزون + كوبون + نقاط ولاء + ضريبة + توصيل).
 *
 * كل الحسابات المالية بالسنت (أعداد صحيحة) وبتتحوّل لـ decimal بس عند الحفظ،
 * فمجموع tax_amount لسطور الطلب بيساوي دايماً ضريبة الطلب (قبل كان بيصير فرق
 * سنت بسبب تقريب كل سطر لحاله وتقريب المجموع لحاله).
 */
class OrderService
{
    /**
     * @param  array  $validated  بيانات الطلب بعد الـ validation (شوف OrderController::store)
     */
    public function create(array $validated, ?int $userId): Order
    {
        // نسبة الضريبة العامة الافتراضية (تُستخدم لأي صنف ما إله ولا لتصنيفه نسبة خاصة)
        $defaultTaxRate = (float) (Setting::get('tax_rate', 0.16));

        $order = DB::transaction(function () use ($validated, $userId, $defaultTaxRate) {
            $requested = $this->aggregateRequestedQuantities($validated['items']);
            $menuItems = $this->lockAndCheckStock($requested);

            // ---------- الأسطر والمجموع الفرعي ----------
            $subtotalC = 0;
            $lines = [];

            foreach ($validated['items'] as $item) {
                $menuItem = $menuItems[$item['menu_item_id']];
                $modifiers = $this->resolveModifiers($menuItem, $item['modifiers'] ?? []);

                $unitC = Money::cents($menuItem->price) + $modifiers->sum(fn ($m) => Money::cents($m->extra_price));
                $lineTotalC = $unitC * $item['quantity'];
                $subtotalC += $lineTotalC;

                // ضريبة قابلة للتخصيص: صنف -> تصنيف -> نسبة عامة افتراضية (شوف MenuItem::effectiveTaxRate)
                $lines[] = [
                    'menu_item' => $menuItem,
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                    'modifiers' => $modifiers,
                    'line_total_c' => $lineTotalC,
                    'tax_rate' => $menuItem->effectiveTaxRate($defaultTaxRate),
                ];
            }

            $this->decrementStock($menuItems, $requested);

            // ---------- الخصومات ----------
            [$couponId, $couponDiscountC] = $this->applyCoupon($validated['coupon_code'] ?? null, $subtotalC);

            $customer = null;
            if (! empty($validated['customer_id'])) {
                // منقفل صف الزبون حتى طلبين متزامنين ما يصرفوا نفس النقاط مرتين
                $customer = Customer::whereKey($validated['customer_id'])->lockForUpdate()->first();
            }

            [$redeemPoints, $pointsDiscountC] = $this->applyLoyaltyPoints(
                $customer,
                (int) ($validated['redeem_points'] ?? 0),
                max($subtotalC - $couponDiscountC, 0)
            );

            $discountC = min($couponDiscountC + $pointsDiscountC, $subtotalC);

            // ---------- الضريبة ----------
            // ضريبة كل سطر بعد تطبيق نسبة الخصم الكلي (كوبون + نقاط) بالتناسب،
            // وضريبة الطلب = مجموع ضرائب الأسطر بالضبط.
            $discountRatio = $subtotalC > 0 ? min($discountC / $subtotalC, 1) : 0;
            $taxC = 0;
            foreach ($lines as &$line) {
                $line['tax_c'] = (int) round($line['line_total_c'] * $line['tax_rate'] * (1 - $discountRatio));
                $taxC += $line['tax_c'];
            }
            unset($line);

            // ---------- رسوم توصيل لطلبات الديليفري فقط ----------
            $deliveryFeeC = 0;
            $deliveryStatus = 'not_applicable';
            if ($validated['order_type'] === 'delivery') {
                $deliveryFeeC = Money::cents(Setting::get('delivery_fee', 2.00));
                $deliveryStatus = 'pending';
            }

            $order = Order::create([
                'order_number' => 'ORD-' . strtoupper(Str::random(8)),
                'table_id' => $validated['table_id'] ?? null,
                'customer_id' => $validated['customer_id'] ?? null,
                // null = طلب عام (QR/أونلاين) بدون موظف
                'user_id' => $userId,
                'source' => $validated['source'] ?? 'pos',
                'public_token' => $validated['public_token'] ?? null,
                'public_request_id' => $validated['public_request_id'] ?? null,
                'coupon_id' => $couponId,
                'order_type' => $validated['order_type'],
                'delivery_address' => $validated['delivery_address'] ?? null,
                'delivery_phone' => $validated['delivery_phone'] ?? null,
                'delivery_fee' => Money::fromCents($deliveryFeeC),
                'delivery_status' => $deliveryStatus,
                'status' => 'pending',
                'subtotal' => Money::fromCents($subtotalC),
                'discount' => Money::fromCents($discountC),
                'tax' => Money::fromCents($taxC),
                'total' => Money::fromCents($subtotalC - $discountC + $taxC + $deliveryFeeC),
                'loyalty_points_redeemed' => $redeemPoints,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $orderItem = $order->items()->create([
                    'menu_item_id' => $line['menu_item']->id,
                    'quantity' => $line['quantity'],
                    'price' => $line['menu_item']->price,
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => Money::fromCents($line['tax_c']),
                    'notes' => $line['notes'],
                ]);

                foreach ($line['modifiers'] as $modifier) {
                    $orderItem->modifiers()->create([
                        'modifier_id' => $modifier->id,
                        'name' => $modifier->name,
                        'extra_price' => $modifier->extra_price,
                    ]);
                }
            }

            if ($redeemPoints > 0 && $customer) {
                $customer->redeemLoyaltyPoints(
                    $redeemPoints,
                    'استبدال نقاط بالطلب ' . $order->order_number,
                    $order->id
                );
            }

            if ($order->table_id) {
                $order->table()->update(['status' => 'occupied']);
            }

            return $order;
        });

        if (($validated['source'] ?? 'pos') === 'pos') {
            AppNotification::notifyRole(
                'admin',
                'order_status',
                'طلب جديد',
                'طلب جديد رقم ' . $order->order_number . ' بقيمة ' . $order->total,
                ['order_id' => $order->id]
            );
        } else {
            // طلب من الزبون مباشرة: لازم الكل (أدمن/مدير/كاشير) ينتبه إله
            $origin = $order->source === 'qr'
                ? 'من طاولة ' . ($order->table?->table_number ?? '')
                : 'أونلاين (' . ($order->order_type === 'delivery' ? 'توصيل' : 'استلام') . ')';

            foreach (['admin', 'manager', 'cashier'] as $role) {
                AppNotification::notifyRole(
                    $role,
                    'order_status',
                    'طلب جديد من الزبون',
                    'طلب رقم ' . $order->order_number . ' ' . $origin . ' بقيمة ' . $order->total,
                    ['order_id' => $order->id]
                );
            }
        }

        return $order;
    }

    /** منجمّع الكمية لكل صنف (نفس الصنف ممكن يتكرر بأكتر من سطر) ومنرتّب بالـ id لتفادي deadlocks */
    private function aggregateRequestedQuantities(array $items): array
    {
        $requested = [];
        foreach ($items as $item) {
            $requested[$item['menu_item_id']] = ($requested[$item['menu_item_id']] ?? 0) + $item['quantity'];
        }
        ksort($requested);

        return $requested;
    }

    /**
     * فحص التوفر والمخزون داخل نفس الـ transaction مع قفل الصفوف
     * (طلبين متزامنين ما لازم يبيعوا نفس القطعة).
     *
     * @return array<int, MenuItem>
     */
    private function lockAndCheckStock(array $requested): array
    {
        $menuItems = [];
        $shortages = [];
        $unavailable = [];

        foreach ($requested as $menuItemId => $qty) {
            $menuItem = MenuItem::with(['category', 'modifierGroups.modifiers'])->whereKey($menuItemId)->lockForUpdate()->firstOrFail();

            if (! $menuItem->is_available) {
                $unavailable[] = $menuItem->name;
            }

            if ($menuItem->track_stock && $menuItem->stock_quantity < $qty) {
                $shortages[] = $menuItem->name . ' (متوفر: ' . $menuItem->stock_quantity . ')';
            }

            $menuItems[$menuItemId] = $menuItem;
        }

        if (! empty($unavailable)) {
            throw ValidationException::withMessages([
                'items' => ['أصناف غير متاحة حاليًا: ' . implode('، ', $unavailable)],
            ]);
        }

        if (! empty($shortages)) {
            throw ValidationException::withMessages([
                'items' => ['الكمية غير متوفرة بالمخزون: ' . implode('، ', $shortages)],
            ]);
        }

        return $menuItems;
    }

    /**
     * الخيارات الإضافية: بنتحقق من
     *  1) كل خيار تابع فعلًا لمجموعة من مجموعات نفس الصنف (مش صنف تاني)
     *  2) الخيار متاح (is_available)
     *  3) المجموعة الإلزامية (is_required) لازم يتختار منها خيار على الأقل
     *  4) ما يتجاوز عدد الخيارات المختارة من المجموعة حد max_selections
     *
     * @return \Illuminate\Support\Collection<int, Modifier> الخيارات المختارة (بدون تكرار)
     */
    private function resolveModifiers(MenuItem $menuItem, array $modifierIds)
    {
        $modifierIds = array_values(array_unique(array_map('intval', $modifierIds)));
        $groups = $menuItem->modifierGroups;

        $allModifiers = $groups->flatMap(fn ($group) => $group->modifiers)->keyBy('id');

        $unknown = array_diff($modifierIds, $allModifiers->keys()->all());
        if (! empty($unknown)) {
            throw ValidationException::withMessages([
                'items' => ['في خيار إضافي غير تابع لصنف "' . $menuItem->name . '"'],
            ]);
        }

        $selected = collect($modifierIds)->map(fn ($id) => $allModifiers[$id])->values();

        $unavailable = $selected->filter(fn ($m) => ! $m->is_available)->pluck('name');
        if ($unavailable->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['خيارات غير متاحة حاليًا على "' . $menuItem->name . '": ' . $unavailable->implode('، ')],
            ]);
        }

        $selectedByGroup = $selected->groupBy('modifier_group_id');

        foreach ($groups as $group) {
            $count = $selectedByGroup->get($group->id, collect())->count();

            // مجموعة إلزامية ما فيها ولا خيار متاح = ما منعلّق الطلب عليها
            $hasAvailable = $group->modifiers->contains(fn ($m) => $m->is_available);

            if ($group->is_required && $hasAvailable && $count === 0) {
                throw ValidationException::withMessages([
                    'items' => ['لازم تختار خيار من مجموعة "' . $group->name . '" لصنف "' . $menuItem->name . '"'],
                ]);
            }

            if ($count > (int) $group->max_selections) {
                throw ValidationException::withMessages([
                    'items' => ['أقصى عدد خيارات من مجموعة "' . $group->name . '" هو ' . $group->max_selections . ' (صنف "' . $menuItem->name . '")'],
                ]);
            }
        }

        return $selected;
    }

    /** @param array<int, MenuItem> $menuItems */
    private function decrementStock(array $menuItems, array $requested): void
    {
        foreach ($menuItems as $menuItemId => $menuItem) {
            if (! $menuItem->track_stock) {
                continue;
            }

            $menuItem->decrement('stock_quantity', $requested[$menuItemId]);
            $menuItem->refresh();

            if ($menuItem->stock_quantity <= $menuItem->low_stock_threshold) {
                AppNotification::notifyRole(
                    'admin',
                    'low_stock',
                    'مخزون منخفض',
                    'الكمية المتبقية من "' . $menuItem->name . '" وصلت لـ ' . $menuItem->stock_quantity
                );
            }
        }
    }

    /**
     * كوبون غير صالح/منتهي = خطأ واضح (مش تجاهل بصمت)، والخصم ما بيتجاوز قيمة الطلب أبدًا.
     *
     * @return array{0: int|null, 1: int} [coupon_id, الخصم بالسنت]
     */
    private function applyCoupon(?string $code, int $subtotalC): array
    {
        if (empty($code)) {
            return [null, 0];
        }

        $coupon = Coupon::where('code', $code)->where('is_active', true)->first();

        if (! $coupon || ($coupon->expires_at && $coupon->expires_at->copy()->endOfDay()->isPast())) {
            throw ValidationException::withMessages([
                'coupon_code' => ['الكوبون غير صالح أو منتهي الصلاحية'],
            ]);
        }

        $discountC = $coupon->type === 'percentage'
            ? (int) round($subtotalC * (min((float) $coupon->value, 100.0) / 100))
            : Money::cents($coupon->value);

        return [$coupon->id, min($discountC, $subtotalC)];
    }

    /**
     * استبدال نقاط الولاء بخصم إضافي: بنخصم فقط النقاط يلي فعلاً تُستخدم
     * (ما بتضيع نقاط على خصم انقطع بالسقف).
     *
     * @return array{0: int, 1: int} [النقاط المستبدلة، الخصم بالسنت]
     */
    private function applyLoyaltyPoints(?Customer $customer, int $requestedPoints, int $maxDiscountC): array
    {
        if (! $customer || $requestedPoints <= 0) {
            return [0, 0];
        }

        $pointValueC = (float) (Setting::get('loyalty_point_value', 0.05)) * 100;
        $usablePoints = $pointValueC > 0 ? (int) floor(($maxDiscountC / $pointValueC) + 1e-9) : 0;

        $redeemPoints = min($requestedPoints, (int) $customer->loyalty_points, $usablePoints);

        if ($redeemPoints <= 0) {
            return [0, 0];
        }

        return [$redeemPoints, (int) round($redeemPoints * $pointValueC)];
    }
}
