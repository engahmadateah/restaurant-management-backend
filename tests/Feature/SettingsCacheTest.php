<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class SettingsCacheTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    public function test_settings_are_read_with_one_query_then_served_from_cache(): void
    {
        Setting::create(['key' => 'tax_rate', 'value' => '0.10']);
        Setting::create(['key' => 'delivery_fee', 'value' => '3']);
        Setting::flushCache();

        DB::enableQueryLog();
        Setting::get('tax_rate');
        Setting::get('delivery_fee');
        Setting::get('missing', 'x');

        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'settings'));
        $this->assertCount(1, $queries);
    }

    public function test_defaults_and_empty_strings_behave_like_before(): void
    {
        Setting::create(['key' => 'phone', 'value' => '']);

        $this->assertSame('', Setting::get('phone', 'fallback'));
        $this->assertSame('fallback', Setting::get('nope', 'fallback'));
        $this->assertNull(Setting::get('nope'));
    }

    public function test_cache_is_flushed_when_a_setting_changes_or_is_deleted(): void
    {
        $setting = Setting::create(['key' => 'currency', 'value' => 'USD']);
        $this->assertSame('USD', Setting::get('currency'));

        $setting->update(['value' => 'EUR']);
        $this->assertSame('EUR', Setting::get('currency'));

        $setting->delete();
        $this->assertNull(Setting::get('currency'));
    }

    public function test_updating_settings_through_the_api_takes_effect_on_the_next_order(): void
    {
        $this->actingAsRole('admin');
        Setting::create(['key' => 'tax_rate', 'value' => '0']);
        $item = $this->makeMenuItem(['price' => 10]);
        $items = [['menu_item_id' => $item->id, 'quantity' => 1]];

        $this->placeOrder($items)->assertCreated()->assertJsonPath('tax', '0.00');

        $this->putJson('/api/settings', ['settings' => ['tax_rate' => '0.5']])->assertOk();

        $this->placeOrder($items)->assertCreated()->assertJsonPath('tax', '5.00');
    }

    public function test_index_returns_the_same_shape_from_cache(): void
    {
        $this->actingAsRole('cashier');
        Setting::create(['key' => 'restaurant_name', 'value' => 'Zaytoun']);

        $this->getJson('/api/settings')->assertOk()->assertJsonPath('restaurant_name', 'Zaytoun');
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('restaurant_name', 'Zaytoun');
    }

    public function test_shift_max_hours_is_validated(): void
    {
        $this->actingAsRole('admin');

        $this->putJson('/api/settings', ['settings' => ['shift_max_hours' => 0]])->assertStatus(422);
        $this->putJson('/api/settings', ['settings' => ['shift_max_hours' => 25]])->assertStatus(422);
        $this->putJson('/api/settings', ['settings' => ['shift_max_hours' => 10]])->assertOk();
        $this->assertSame('10', Setting::get('shift_max_hours'));
    }
}
