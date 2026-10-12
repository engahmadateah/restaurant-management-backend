<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class SettingsValidationTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        Setting::create(['key' => 'tax_rate', 'value' => '0.16']);
    }

    private function save(array $settings)
    {
        return $this->putJson('/api/settings', ['settings' => $settings]);
    }

    public function test_valid_settings_are_saved_as_strings(): void
    {
        $this->save([
            'restaurant_name' => 'مطعم زيتون',
            'currency' => 'ل.س',
            'tax_rate' => 0.1,
            'delivery_fee' => 7000,
            'loyalty_points_per_currency' => '0.001',
            'loyalty_point_value' => '200',
            'phone' => '0930000000',
            'address' => 'دمشق',
        ])->assertOk();

        $this->assertSame('0.1', Setting::where('key', 'tax_rate')->value('value'));
        $this->assertSame('7000', Setting::where('key', 'delivery_fee')->value('value'));
        $this->assertSame('مطعم زيتون', Setting::where('key', 'restaurant_name')->value('value'));
    }

    public function test_partial_update_only_touches_the_given_keys(): void
    {
        $this->save(['delivery_fee' => 5])->assertOk();

        $this->assertSame('0.16', Setting::where('key', 'tax_rate')->value('value'));
        $this->assertSame('5', Setting::where('key', 'delivery_fee')->value('value'));
    }

    public function test_invalid_numbers_are_rejected_and_nothing_is_saved(): void
    {
        foreach (['abc', 1.5, -0.1, ''] as $bad) {
            $this->save(['tax_rate' => $bad, 'delivery_fee' => 9])
                ->assertStatus(422)->assertJsonValidationErrors('settings.tax_rate');
        }

        $this->assertSame('0.16', Setting::where('key', 'tax_rate')->value('value'));
        $this->assertNull(Setting::where('key', 'delivery_fee')->value('value'));

        $this->save(['delivery_fee' => -1])->assertStatus(422)->assertJsonValidationErrors('settings.delivery_fee');
        $this->save(['loyalty_point_value' => 'x'])->assertStatus(422)->assertJsonValidationErrors('settings.loyalty_point_value');
    }

    public function test_unknown_keys_are_rejected(): void
    {
        $this->save(['tax_rate' => 0.1, 'evil_flag' => '1'])
            ->assertStatus(422)->assertJsonValidationErrors('settings');

        $this->assertSame('0.16', Setting::where('key', 'tax_rate')->value('value'));
        $this->assertNull(Setting::where('key', 'evil_flag')->value('value'));
    }

    public function test_required_text_settings_cannot_be_emptied_but_phone_can(): void
    {
        $this->save(['restaurant_name' => ''])->assertStatus(422)->assertJsonValidationErrors('settings.restaurant_name');
        $this->save(['currency' => ''])->assertStatus(422)->assertJsonValidationErrors('settings.currency');

        $this->save(['phone' => ''])->assertOk();
        $this->assertSame('', Setting::where('key', 'phone')->value('value'));
    }

    public function test_settings_must_be_an_array(): void
    {
        $this->putJson('/api/settings', ['settings' => 'nope'])->assertStatus(422);
        $this->putJson('/api/settings', [])->assertStatus(422);
    }
}
