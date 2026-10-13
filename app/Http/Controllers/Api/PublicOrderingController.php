<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Services\OrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;


class PublicOrderingController extends Controller
{
    public function __construct(private OrderService $orders)
    {
    }

    private function flag(string $key): bool
    {
        return Setting::get($key, '0') === '1';
    }


    public function info()
    {
        return response()->json([
            'restaurant_name' => (string) Setting::get('restaurant_name', ''),
            'phone' => (string) Setting::get('phone', ''),
            'address' => (string) Setting::get('address', ''),
            'currency' => (string) Setting::get('currency', ''),
            'delivery_fee' => (float) Setting::get('delivery_fee', 2.00),
            'qr_ordering_enabled' => $this->flag('qr_ordering_enabled'),
            'online_ordering_enabled' => $this->flag('online_ordering_enabled'),
            'online_min_order' => (float) Setting::get('online_min_order', 0),
        ]);
    }

   
    public function menu(Request $request)
    {
        $lang = preg_match('/^[a-z]{2}$/', (string) $request->query('lang')) ? $request->query('lang') : null;

        $categories = Category::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        $defaultTax = (float) Setting::get('tax_rate', 0.16);

        $items = MenuItem::query()
            ->with([
                'category',
                'modifierGroups' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                'modifierGroups.modifiers' => fn ($q) => $q->where('is_available', true)->orderBy('sort_order')->orderBy('id'),
            ])
            ->where('is_available', true)
            ->whereIn('category_id', $categories->pluck('id'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('category_id');

        $result = $categories
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->translate('name', $lang),
                'items' => ($items[$category->id] ?? collect())->map(fn (MenuItem $item) => $this->menuItemPayload($item, $lang, $defaultTax))->values()->all(),
            ])
            ->filter(fn (array $c) => count($c['items']) > 0)
            ->values();

        return response()->json(['categories' => $result]);
    }

    private function menuItemPayload(MenuItem $item, ?string $lang, float $defaultTax): array
    {
        return [
            'id' => $item->id,
            'name' => $item->translate('name', $lang),
            'description' => $item->translate('description', $lang),
            'price' => (float) $item->price,
          
            
            'tax_rate' => $item->effectiveTaxRate($defaultTax),
           
            'image' => $item->image,
           
            'sold_out' => $item->track_stock && $item->stock_quantity <= 0,
            'modifier_groups' => $item->modifierGroups
                ->map(fn ($group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'is_required' => (bool) $group->is_required,
                    'max_selections' => $group->max_selections,
                    'modifiers' => $group->modifiers->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'extra_price' => (float) $m->extra_price,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    
    public function table(string $token)
    {
        $table = $this->findTable($token);
        abort_unless($table, 404, 'هالطاولة غير موجودة. امسح الـ QR من جديد');

        return response()->json([
            'table_number' => $table->table_number,
            'ordering_enabled' => $this->flag('qr_ordering_enabled'),
        ]);
    }

    private function findTable(string $token): ?RestaurantTable
    {
        
        return preg_match('/^[A-Za-z0-9]{40}$/', $token)
            ? RestaurantTable::where('qr_token', $token)->first()
            : null;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{16,64}$/'],
            'order_type' => ['required', Rule::in(['dine_in', 'takeaway', 'delivery'])],
            'table_token' => ['required_if:order_type,dine_in', 'nullable', 'string', 'max:64'],
            'customer_name' => ['required_unless:order_type,dine_in', 'nullable', 'string', 'max:100'],
            'customer_phone' => ['required_unless:order_type,dine_in', 'nullable', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'delivery_address' => ['required_if:order_type,delivery', 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.menu_item_id' => ['required', 'integer', Rule::exists('menu_items', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.notes' => ['nullable', 'string', 'max:200'],
            'items.*.modifiers' => ['sometimes', 'array', 'max:20'],
            'items.*.modifiers.*' => ['integer', 'exists:modifiers,id'],
        ]);

        $type = $data['order_type'];

        $enabled = $type === 'dine_in' ? $this->flag('qr_ordering_enabled') : $this->flag('online_ordering_enabled');
        if (! $enabled) {
            return response()->json(['message' => 'الطلب غير متاح حالياً، اطلب من الموظف'], 403);
        }

       
        $existing = Order::where('public_request_id', $data['client_request_id'])->first();
        if ($existing) {
            return response()->json($this->payload($existing), 200);
        }

        $table = null;
        if ($type === 'dine_in') {
            $table = $this->findTable((string) $data['table_token']);
            if (! $table) {
                throw ValidationException::withMessages([
                    'table_token' => ['رمز الطاولة غير صالح. امسح الـ QR على الطاولة من جديد'],
                ]);
            }
        }

        $phone = null;
        $customer = null;
        if ($type !== 'dine_in') {
            $phone = preg_replace('/[^0-9+]/', '', $data['customer_phone']);
            $customer = $this->resolveCustomer(trim($data['customer_name']), $phone);
        }

        $items = collect($data['items'])->map(fn (array $i) => [
            'menu_item_id' => (int) $i['menu_item_id'],
            'quantity' => (int) $i['quantity'],
            'notes' => $i['notes'] ?? null,
            'modifiers' => array_map('intval', $i['modifiers'] ?? []),
        ])->all();

        try {
            $order = DB::transaction(function () use ($data, $type, $table, $customer, $phone, $items) {
                $order = $this->orders->create([
                    'order_type' => $type,
                    'table_id' => $table?->id,
                    'customer_id' => $customer?->id,
                    'delivery_address' => $type === 'delivery' ? trim($data['delivery_address']) : null,
                    'delivery_phone' => $type === 'delivery' ? $phone : null,
                    'notes' => $data['notes'] ?? null,
                    'items' => $items,
                    'source' => $type === 'dine_in' ? 'qr' : 'online',
                    'public_token' => Str::random(40),
                    'public_request_id' => $data['client_request_id'],
                ], null);

                
                $min = (float) Setting::get('online_min_order', 0);
                if ($type !== 'dine_in' && $min > 0 && (float) $order->subtotal < $min) {
                    throw ValidationException::withMessages([
                        'items' => ['الحد الأدنى للطلب ' . number_format($min, 2)],
                    ]);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
          
            $existing = Order::where('public_request_id', $data['client_request_id'])->first();
            if ($existing) {
                return response()->json($this->payload($existing), 200);
            }

            throw $e;
        }

        return response()->json($this->payload($order), 201);
    }

  
    private function resolveCustomer(string $name, string $phone): Customer
    {
        try {
            return Customer::firstOrCreate(['phone' => $phone], ['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            return Customer::where('phone', $phone)->firstOrFail();
        }
    }

   
    public function track(string $token)
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token), 404);

        $order = Order::where('public_token', $token)->first();
        abort_unless($order, 404, 'الطلب غير موجود');

        return response()->json($this->payload($order));
    }

    private function payload(Order $order): array
    {
        $order->loadMissing(['items.menuItem', 'items.modifiers', 'table']);

        return [
            'token' => $order->public_token,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'order_type' => $order->order_type,
            'delivery_status' => $order->delivery_status,
            'payment_status' => $order->payment_status,
            'table_number' => $order->table?->table_number,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'tax' => (float) $order->tax,
            'delivery_fee' => (float) $order->delivery_fee,
            'total' => (float) $order->total,
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->menuItem?->name,
                'quantity' => $i->quantity,
                'price' => (float) $i->price,
                'notes' => $i->notes,
                'modifiers' => $i->modifiers->pluck('name')->all(),
            ])->values()->all(),
        ];
    }
}
