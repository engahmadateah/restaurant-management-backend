<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

/** الواجهة العامة للزبون: قائمة، طلب من الطاولة (QR)، طلب أونلاين، تتبّع. كلها بدون تسجيل دخول. */
class PublicOrderingTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function enable(bool $qr = true, bool $online = true, float $min = 0): void
    {
        Setting::updateOrCreate(['key' => 'qr_ordering_enabled'], ['value' => $qr ? '1' : '0']);
        Setting::updateOrCreate(['key' => 'online_ordering_enabled'], ['value' => $online ? '1' : '0']);
        Setting::updateOrCreate(['key' => 'online_min_order'], ['value' => (string) $min]);
        Setting::updateOrCreate(['key' => 'tax_rate'], ['value' => '0.10']);
        Setting::updateOrCreate(['key' => 'delivery_fee'], ['value' => '2.00']);
    }

    private function table(string $number = 'T-1'): RestaurantTable
    {
        $table = RestaurantTable::create(['table_number' => $number, 'capacity' => 4, 'status' => 'available']);
        $table->forceFill(['qr_token' => Str::random(40)])->save();

        return $table->fresh()->makeVisible('qr_token');
    }

    private function body(array $over = []): array
    {
        return array_merge([
            'client_request_id' => 'req_' . Str::random(20),
            'order_type' => 'takeaway',
            'customer_name' => 'Samer',
            'customer_phone' => '0933 111 222',
            'items' => [['menu_item_id' => $this->makeMenuItem(['price' => 20])->id, 'quantity' => 2]],
        ], $over);
    }

    // ---------- القراءة ----------

    public function test_info_exposes_only_public_fields_and_defaults_to_disabled(): void
    {
        Setting::updateOrCreate(['key' => 'restaurant_name'], ['value' => 'زيتون']);
        Setting::updateOrCreate(['key' => 'payroll_secret'], ['value' => 'x']);

        $res = $this->getJson('/api/public/info')->assertOk();

        $this->assertSame('زيتون', $res->json('restaurant_name'));
        $this->assertFalse($res->json('qr_ordering_enabled'));
        $this->assertFalse($res->json('online_ordering_enabled'));
        $this->assertEqualsCanonicalizing(
            ['restaurant_name', 'phone', 'address', 'currency', 'delivery_fee', 'qr_ordering_enabled', 'online_ordering_enabled', 'online_min_order'],
            array_keys($res->json())
        );
    }

    public function test_menu_shows_only_active_categories_and_available_items_without_internal_fields(): void
    {
        $this->enable();
        $active = Category::create(['name' => 'Grill', 'slug' => 'grill', 'is_active' => true, 'sort_order' => 1]);
        $hidden = Category::create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false, 'sort_order' => 2]);
        Category::create(['name' => 'Empty', 'slug' => 'empty', 'is_active' => true, 'sort_order' => 3]);

        $shown = $this->makeMenuItem(['category_id' => $active->id, 'name' => 'Kebab', 'price' => 12.5, 'track_stock' => true, 'stock_quantity' => 7]);
        $this->makeMenuItem(['category_id' => $active->id, 'name' => 'Off', 'is_available' => false]);
        $this->makeMenuItem(['category_id' => $hidden->id, 'name' => 'InHiddenCategory']);
        $deleted = $this->makeMenuItem(['category_id' => $active->id, 'name' => 'Deleted']);
        $deleted->delete();
        $soldOut = $this->makeMenuItem(['category_id' => $active->id, 'name' => 'Gone', 'track_stock' => true, 'stock_quantity' => 0]);

        $group = ModifierGroup::create(['menu_item_id' => $shown->id, 'name' => 'Size', 'is_required' => false, 'max_selections' => 1, 'sort_order' => 1]);
        Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Large', 'extra_price' => 3, 'is_available' => true, 'sort_order' => 1]);
        Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Unavailable', 'extra_price' => 1, 'is_available' => false, 'sort_order' => 2]);

        $res = $this->getJson('/api/public/menu')->assertOk();

        $categories = collect($res->json('categories'));
        $this->assertSame(['Grill'], $categories->pluck('name')->all());

        $items = collect($categories[0]['items'])->keyBy('name');
        $this->assertEqualsCanonicalizing(['Kebab', 'Gone'], $items->keys()->all());
        $this->assertSame(12.5, $items['Kebab']['price']);
        $this->assertEquals(0.10, $items['Kebab']['tax_rate']);
        $this->assertFalse($items['Kebab']['sold_out']);
        $this->assertTrue($items['Gone']['sold_out']);

        // لا مخزون ولا حقول داخلية
        foreach (['stock_quantity', 'track_stock', 'low_stock_threshold', 'is_low_stock', 'translations', 'category_id', 'is_available'] as $leak) {
            $this->assertArrayNotHasKey($leak, $items['Kebab']);
        }

        // الخيار المتاح بس
        $this->assertSame(['Large'], collect($items['Kebab']['modifier_groups'][0]['modifiers'])->pluck('name')->all());
    }

    public function test_menu_uses_item_tax_override_and_translation(): void
    {
        $this->enable();
        $item = $this->makeMenuItem(['name' => 'حمص', 'tax_rate' => 0, 'translations' => ['en' => ['name' => 'Hummus']]]);

        $res = $this->getJson('/api/public/menu?lang=en')->assertOk();
        $row = collect($res->json('categories.0.items'))->firstWhere('id', $item->id);

        $this->assertSame('Hummus', $row['name']);
        $this->assertEquals(0, $row['tax_rate']);
    }

    public function test_table_lookup_by_token(): void
    {
        $this->enable();
        $table = $this->table('T-9');

        $this->getJson("/api/public/tables/{$table->qr_token}")
            ->assertOk()
            ->assertJson(['table_number' => 'T-9', 'ordering_enabled' => true]);

        $this->getJson('/api/public/tables/' . Str::random(40))->assertNotFound();
        $this->getJson('/api/public/tables/1')->assertNotFound(); // مو رمز: ما بتخمّن بالـ id
    }

    // ---------- الطلب من الطاولة (QR) ----------

    public function test_qr_dine_in_order_is_created_with_server_side_prices_and_no_user(): void
    {
        $this->enable();
        $table = $this->table();
        // موظف لاستقبال الإشعار بس (مو مسجّل دخول: الطلب نفسه عام)
        $admin = User::create(['name' => 'Admin', 'email' => 'a@t.local', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
        $item = $this->makeMenuItem(['price' => 10]);

        $res = $this->postJson('/api/public/orders', [
            'client_request_id' => 'req_' . Str::random(20),
            'order_type' => 'dine_in',
            'table_token' => $table->qr_token,
            'notes' => 'بدون بصل',
            // محاولة غش: المبالغ ما بتنقبل من الزبون
            'price' => 0, 'total' => 0, 'discount' => 999,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 3, 'price' => 0]],
        ])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame('qr', $order->source);
        $this->assertNull($order->user_id);
        $this->assertSame($table->id, $order->table_id);
        $this->assertSame('dine_in', $order->order_type);
        $this->assertEquals(30.00, $order->subtotal);
        $this->assertEquals(3.00, $order->tax);      // 10% من 30
        $this->assertEquals(33.00, $order->total);
        $this->assertSame('occupied', $table->fresh()->status);

        $this->assertSame($order->public_token, $res->json('token'));
        $this->assertSame(40, strlen($res->json('token')));
        $this->assertEquals(33.0, $res->json('total'));
        $this->assertSame('T-1', $res->json('table_number'));

        // الموظفين بينتبهوا للطلب
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'طلب جديد من الزبون')->exists());
    }

    public function test_dine_in_requires_a_valid_table_token(): void
    {
        $this->enable();
        $table = $this->table();
        $item = $this->makeMenuItem();
        $base = ['order_type' => 'dine_in', 'items' => [['menu_item_id' => $item->id, 'quantity' => 1]]];

        $this->postJson('/api/public/orders', $base + ['client_request_id' => 'req_' . Str::random(20)])
            ->assertStatus(422)->assertJsonValidationErrors('table_token');

        $this->postJson('/api/public/orders', $base + ['client_request_id' => 'req_' . Str::random(20), 'table_token' => Str::random(40)])
            ->assertStatus(422)->assertJsonValidationErrors('table_token');

        // table_id مباشرة (بدل الرمز) ما بيفيد
        $this->postJson('/api/public/orders', $base + ['client_request_id' => 'req_' . Str::random(20), 'table_token' => (string) $table->id, 'table_id' => $table->id])
            ->assertStatus(422);

        $this->assertSame(0, Order::count());
    }

    public function test_ordering_is_closed_unless_enabled_per_channel(): void
    {
        $table = $this->table();
        $item = $this->makeMenuItem();
        $items = [['menu_item_id' => $item->id, 'quantity' => 1]];

        // الافتراضي: الكل مقفول
        $this->postJson('/api/public/orders', ['client_request_id' => 'req_' . Str::random(20), 'order_type' => 'dine_in', 'table_token' => $table->qr_token, 'items' => $items])->assertForbidden();
        $this->postJson('/api/public/orders', $this->body())->assertForbidden();

        // QR بس مفعّل: الأونلاين لسا مقفول
        $this->enable(qr: true, online: false);
        $this->postJson('/api/public/orders', $this->body())->assertForbidden();
        $this->postJson('/api/public/orders', ['client_request_id' => 'req_' . Str::random(20), 'order_type' => 'dine_in', 'table_token' => $table->qr_token, 'items' => $items])->assertCreated();

        $this->assertSame(1, Order::count());
    }

    // ---------- الطلب الأونلاين ----------

    public function test_online_takeaway_creates_customer_by_phone_and_never_renames_an_existing_one(): void
    {
        $this->enable();
        $existing = Customer::create(['name' => 'Original Name', 'phone' => '0933111222', 'loyalty_points' => 0]);

        $res = $this->postJson('/api/public/orders', $this->body(['customer_name' => 'Someone Else']))->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame('online', $order->source);
        $this->assertSame('takeaway', $order->order_type);
        $this->assertSame($existing->id, $order->customer_id);
        $this->assertSame('Original Name', $existing->fresh()->name);
        $this->assertSame(1, Customer::count());
        $this->assertEquals(0, $order->delivery_fee);

        $this->postJson('/api/public/orders', $this->body(['customer_phone' => '0944000555', 'customer_name' => 'New Guy']))->assertCreated();
        $this->assertSame('New Guy', Customer::where('phone', '0944000555')->value('name'));
        $this->assertNotEmpty($res->json('token'));
    }

    public function test_online_order_requires_name_and_phone_and_delivery_requires_address(): void
    {
        $this->enable();

        $this->postJson('/api/public/orders', $this->body(['customer_name' => null]))->assertStatus(422)->assertJsonValidationErrors('customer_name');
        $this->postJson('/api/public/orders', $this->body(['customer_phone' => null]))->assertStatus(422)->assertJsonValidationErrors('customer_phone');
        $this->postJson('/api/public/orders', $this->body(['customer_phone' => 'abc']))->assertStatus(422)->assertJsonValidationErrors('customer_phone');
        $this->postJson('/api/public/orders', $this->body(['order_type' => 'delivery']))->assertStatus(422)->assertJsonValidationErrors('delivery_address');

        $this->assertSame(0, Order::count());
    }

    public function test_delivery_order_adds_fee_and_keeps_the_address_private(): void
    {
        $this->enable();

        $res = $this->postJson('/api/public/orders', $this->body(['order_type' => 'delivery', 'delivery_address' => 'Mazzeh, bldg 4']))->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame('Mazzeh, bldg 4', $order->delivery_address);
        $this->assertSame('0933111222', $order->delivery_phone);
        $this->assertEquals(2.00, $order->delivery_fee);
        $this->assertSame('pending', $order->delivery_status);
        // 40 + 4 ضريبة + 2 توصيل
        $this->assertEquals(46.0, $res->json('total'));

        $json = json_encode($res->json());
        $this->assertStringNotContainsString('Mazzeh', $json);
        $this->assertStringNotContainsString('0933111222', $json);
    }

    public function test_online_minimum_order_is_enforced_and_rolls_everything_back(): void
    {
        $this->enable(min: 100);
        $item = $this->makeMenuItem(['price' => 10, 'track_stock' => true, 'stock_quantity' => 5, 'low_stock_threshold' => 1]);

        $this->postJson('/api/public/orders', $this->body(['items' => [['menu_item_id' => $item->id, 'quantity' => 2]]]))
            ->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $item->fresh()->stock_quantity); // المخزون ما انخصم
    }

    // ---------- المخزون والتوفر ----------

    public function test_unavailable_or_oversold_items_are_rejected(): void
    {
        $this->enable();
        $off = $this->makeMenuItem(['is_available' => false]);
        $tracked = $this->makeMenuItem(['track_stock' => true, 'stock_quantity' => 2, 'low_stock_threshold' => 0]);

        $this->postJson('/api/public/orders', $this->body(['items' => [['menu_item_id' => $off->id, 'quantity' => 1]]]))->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson('/api/public/orders', $this->body(['items' => [['menu_item_id' => $tracked->id, 'quantity' => 3]]]))->assertStatus(422)->assertJsonValidationErrors('items');

        $this->postJson('/api/public/orders', $this->body(['items' => [['menu_item_id' => $tracked->id, 'quantity' => 2]]]))->assertCreated();
        $this->assertSame(0, $tracked->fresh()->stock_quantity);
    }

    public function test_abusive_payload_sizes_are_rejected(): void
    {
        $this->enable();
        $item = $this->makeMenuItem();

        $this->postJson('/api/public/orders', $this->body(['items' => [['menu_item_id' => $item->id, 'quantity' => 21]]]))->assertStatus(422);
        $this->postJson('/api/public/orders', $this->body(['items' => array_fill(0, 41, ['menu_item_id' => $item->id, 'quantity' => 1])]))->assertStatus(422);
        $this->postJson('/api/public/orders', $this->body(['notes' => str_repeat('x', 501)]))->assertStatus(422);
        $this->postJson('/api/public/orders', $this->body(['client_request_id' => 'short']))->assertStatus(422)->assertJsonValidationErrors('client_request_id');
        $this->assertSame(0, Order::count());
    }

    // ---------- منع التكرار والتتبّع ----------

    public function test_same_client_request_id_creates_one_order(): void
    {
        $this->enable();
        $item = $this->makeMenuItem(['track_stock' => true, 'stock_quantity' => 10, 'low_stock_threshold' => 0]);
        $body = $this->body(['items' => [['menu_item_id' => $item->id, 'quantity' => 2]]]);

        $first = $this->postJson('/api/public/orders', $body)->assertCreated();
        $second = $this->postJson('/api/public/orders', $body)->assertOk();

        $this->assertSame($first->json('token'), $second->json('token'));
        $this->assertSame(1, Order::count());
        $this->assertSame(8, $item->fresh()->stock_quantity); // انخصم مرة وحدة
    }

    public function test_tracking_returns_status_and_items_only(): void
    {
        $this->enable();
        $token = $this->postJson('/api/public/orders', $this->body())->assertCreated()->json('token');

        $res = $this->getJson("/api/public/orders/{$token}")->assertOk();
        $this->assertSame('pending', $res->json('status'));
        $this->assertCount(1, $res->json('items'));
        $this->assertArrayNotHasKey('customer', $res->json());
        $this->assertArrayNotHasKey('delivery_phone', $res->json());
        $this->assertArrayNotHasKey('id', $res->json());

        Order::firstOrFail()->update(['status' => 'preparing']);
        $this->assertSame('preparing', $this->getJson("/api/public/orders/{$token}")->json('status'));

        $this->getJson('/api/public/orders/' . Str::random(40))->assertNotFound();
        $this->getJson('/api/public/orders/ORD-ABCDEFGH')->assertNotFound(); // رقم الطلب مو رمز تتبّع
        $this->getJson('/api/public/orders/1')->assertNotFound();
    }

    public function test_public_order_creation_is_throttled_per_ip(): void
    {
        $this->enable();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/public/orders', [])->assertStatus(422);
        }

        $this->postJson('/api/public/orders', [])->assertStatus(429);
    }

    // ---------- جهة الإدارة ----------

    public function test_staff_never_see_public_tokens_in_normal_apis(): void
    {
        $this->enable();
        $table = $this->table();
        $this->postJson('/api/public/orders', $this->body())->assertCreated();

        $this->actingAsRole('admin');
        $tables = $this->getJson('/api/tables')->assertOk();
        $this->assertStringNotContainsString($table->qr_token, json_encode($tables->json()));

        $orders = $this->getJson('/api/orders')->assertOk();
        $this->assertStringNotContainsString('public_token', json_encode($orders->json()));
        $this->assertStringNotContainsString('public_request_id', json_encode($orders->json()));
    }

    public function test_qr_token_endpoints_require_tables_manage_and_rotation_invalidates_the_old_qr(): void
    {
        $this->enable();
        $table = RestaurantTable::create(['table_number' => 'T-5', 'capacity' => 2, 'status' => 'available']);

        $this->actingAsRole('cashier');
        $this->getJson("/api/tables/{$table->id}/qr")->assertForbidden();
        $this->postJson("/api/tables/{$table->id}/qr/rotate")->assertForbidden();

        $this->actingAsRole('manager');
        $first = $this->getJson("/api/tables/{$table->id}/qr")->assertOk();
        $this->assertSame(40, strlen($first->json('token')));
        $this->assertSame('T-5', $first->json('table_number'));
        // ثابت طالما ما اتغيّر
        $this->assertSame($first->json('token'), $this->getJson("/api/tables/{$table->id}/qr")->json('token'));

        $rotated = $this->postJson("/api/tables/{$table->id}/qr/rotate")->assertOk();
        $this->assertNotSame($first->json('token'), $rotated->json('token'));

        // الـ endpoints العامة ما بتتأثر بوجود مستخدم مسجّل
        $this->getJson('/api/public/tables/' . $first->json('token'))->assertNotFound();
        $this->getJson('/api/public/tables/' . $rotated->json('token'))->assertOk();
    }

    public function test_settings_toggles_are_stored_as_one_or_zero(): void
    {
        $this->actingAsRole('admin');

        $this->putJson('/api/settings', ['settings' => ['qr_ordering_enabled' => true, 'online_ordering_enabled' => false, 'online_min_order' => 15]])->assertOk();
        $this->assertSame('1', Setting::get('qr_ordering_enabled'));
        $this->assertSame('0', Setting::get('online_ordering_enabled'));
        $this->assertSame('15', Setting::get('online_min_order'));

        $this->putJson('/api/settings', ['settings' => ['qr_ordering_enabled' => 'maybe']])->assertStatus(422);
        $this->putJson('/api/settings', ['settings' => ['online_min_order' => -1]])->assertStatus(422);

        $info = $this->getJson('/api/public/info')->assertOk();
        $this->assertTrue($info->json('qr_ordering_enabled'));
        $this->assertFalse($info->json('online_ordering_enabled'));
        $this->assertEquals(15, $info->json('online_min_order'));
    }

    public function test_staff_can_filter_orders_by_source(): void
    {
        $this->enable();
        $table = $this->table();
        $this->postJson('/api/public/orders', $this->body())->assertCreated();
        $this->postJson('/api/public/orders', ['client_request_id' => 'req_' . Str::random(20), 'order_type' => 'dine_in', 'table_token' => $table->qr_token, 'items' => [['menu_item_id' => $this->makeMenuItem()->id, 'quantity' => 1]]])->assertCreated();

        $this->actingAsRole('admin');
        $this->makeOrder(50);

        $this->assertSame(1, $this->getJson('/api/orders?source=online')->json('total'));
        $this->assertSame(1, $this->getJson('/api/orders?source=qr')->json('total'));
        $this->assertSame(1, $this->getJson('/api/orders?source=pos')->json('total'));
        $this->assertSame(3, $this->getJson('/api/orders')->json('total'));
        $this->getJson('/api/orders?source=weird')->assertStatus(422);
    }
}
