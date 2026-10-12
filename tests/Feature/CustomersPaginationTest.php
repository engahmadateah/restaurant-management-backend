<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class CustomersPaginationTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    public function test_paginates_when_page_or_per_page_is_given(): void
    {
        $this->actingAsRole('admin');
        for ($i = 0; $i < 12; $i++) {
            $this->makeCustomer();
        }

        $res = $this->getJson('/api/customers?per_page=5')->assertOk();
        $this->assertCount(5, $res->json('data'));
        $this->assertSame(12, $res->json('total'));
        $this->assertSame(3, $res->json('last_page'));
        $this->assertCount(2, $this->getJson('/api/customers?per_page=5&page=3')->json('data'));
        // السقف الأقصى
        $this->assertSame(100, $this->getJson('/api/customers?per_page=100000')->json('per_page'));
    }

    public function test_without_paging_params_keeps_the_legacy_plain_array(): void
    {
        $this->actingAsRole('admin');
        for ($i = 0; $i < 3; $i++) {
            $this->makeCustomer();
        }

        $res = $this->getJson('/api/customers')->assertOk();
        $this->assertTrue(array_is_list($res->json()));
        $this->assertCount(3, $res->json());
        $this->assertCount(3, $this->getJson('/api/customers?per_page=all')->json());
    }

    public function test_search_by_name_or_phone_works_with_and_without_paging(): void
    {
        $this->actingAsRole('admin');
        $a = $this->makeCustomer(['name' => 'Layla Nasser', 'phone' => '0944000111']);
        $this->makeCustomer(['name' => 'Omar', 'phone' => '0955000222']);

        $this->assertSame([$a->id], collect($this->getJson('/api/customers?search=Layla')->json())->pluck('id')->all());
        $this->assertSame([$a->id], collect($this->getJson('/api/customers?search=0944')->json())->pluck('id')->all());
        $this->assertSame(1, $this->getJson('/api/customers?search=Layla&per_page=10')->json('total'));
        // % حرفي مو wildcard
        $this->assertSame([], $this->getJson('/api/customers?search=' . urlencode('%'))->json());
    }
}
