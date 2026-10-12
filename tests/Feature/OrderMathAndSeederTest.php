<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class OrderMathAndSeederTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    public function test_order_tax_equals_sum_of_line_taxes_even_with_odd_prices(): void
    {
        $this->actingAsRole('admin');

        // أسعار بتعمل كسور سنت عند الضريبة 16%
        $a = $this->makeMenuItem(['price' => 3.33]);
        $b = $this->makeMenuItem(['price' => 7.77]);
        $c = $this->makeMenuItem(['price' => 1.01]);

        $res = $this->placeOrder([
            ['menu_item_id' => $a->id, 'quantity' => 3],
            ['menu_item_id' => $b->id, 'quantity' => 1],
            ['menu_item_id' => $c->id, 'quantity' => 7],
        ])->assertCreated();

        $lineTaxCents = collect($res->json('items'))->sum(fn ($i) => (int) round($i['tax_amount'] * 100));

        $this->assertSame($lineTaxCents, (int) round($res->json('tax') * 100));
        $this->assertSame(
            (int) round($res->json('subtotal') * 100) - (int) round($res->json('discount') * 100) + $lineTaxCents,
            (int) round($res->json('total') * 100)
        );
    }

    public function test_tax_stays_consistent_when_a_coupon_discount_is_applied(): void
    {
        $this->actingAsRole('admin');
        \App\Models\Coupon::create([
            'code' => 'TEN', 'type' => 'percentage', 'value' => 10, 'is_active' => true,
        ]);

        // هالمبلغين مع خصم 10% كانوا يعطوا ضريبة طلب 6.09 بينما مجموع ضرائب الأسطر 6.10 (تقريب مرتين)
        $a = $this->makeMenuItem(['price' => 35.75]);
        $b = $this->makeMenuItem(['price' => 6.56]);

        $res = $this->placeOrder(
            [['menu_item_id' => $a->id, 'quantity' => 1], ['menu_item_id' => $b->id, 'quantity' => 1]],
            ['coupon_code' => 'TEN']
        )->assertCreated();

        $lineTaxCents = collect($res->json('items'))->sum(fn ($i) => (int) round($i['tax_amount'] * 100));
        $this->assertSame($lineTaxCents, (int) round($res->json('tax') * 100));
        $this->assertSame(609, $lineTaxCents); // 6.09264 مقرّبة مرة وحدة بالسنت
    }

    public function test_invalid_per_page_values_fall_back_to_a_safe_page_size(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/orders?per_page=0')->assertOk()->assertJsonPath('per_page', 20);
        $this->getJson('/api/orders?per_page=-5')->assertOk()->assertJsonPath('per_page', 20);
        $this->getJson('/api/orders?per_page=100000')->assertOk()->assertJsonPath('per_page', 100);
        $this->getJson('/api/users?per_page=100000')->assertOk()->assertJsonPath('per_page', 100);
    }

    public function test_seeder_uses_known_password_only_in_local_and_testing(): void
    {
        // بيئة الاختبار (testing): كلمة السر المعروفة مسموحة
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(Hash::check('password', User::where('email', 'admin@restaurant.com')->value('password')));
    }

    public function test_seeder_generates_a_random_password_in_production(): void
    {
        $this->app['env'] = 'production';

        // نستدعي السيدر مباشرة: أمر db:seed بالـ production بيطلب تأكيد تفاعلي
        $this->app->make(DatabaseSeeder::class)->run();

        $hash = User::where('email', 'admin@restaurant.com')->value('password');
        $this->assertFalse(Hash::check('password', $hash));
    }

    public function test_seeder_uses_configured_password_when_provided(): void
    {
        $this->app['env'] = 'production';
        putenv('SEED_DEFAULT_PASSWORD=Chosen-Pass-123');
        $_ENV['SEED_DEFAULT_PASSWORD'] = 'Chosen-Pass-123';

        try {
            // نستدعي السيدر مباشرة: أمر db:seed بالـ production بيطلب تأكيد تفاعلي
        $this->app->make(DatabaseSeeder::class)->run();
            $hash = User::where('email', 'cashier@restaurant.com')->value('password');
            $this->assertTrue(Hash::check('Chosen-Pass-123', $hash));
        } finally {
            putenv('SEED_DEFAULT_PASSWORD');
            unset($_ENV['SEED_DEFAULT_PASSWORD']);
        }
    }
}
