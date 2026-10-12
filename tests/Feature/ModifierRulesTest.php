<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class ModifierRulesTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private MenuItem $item;

    private ModifierGroup $size;

    private ModifierGroup $extras;

    /** @var array<string, Modifier> */
    private array $mod = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');

        $this->item = $this->makeMenuItem(['price' => 10]);

        // الحجم: إلزامي، اختيار واحد
        $this->size = ModifierGroup::create(['menu_item_id' => $this->item->id, 'name' => 'Size', 'is_required' => true, 'max_selections' => 1]);
        $this->mod['medium'] = Modifier::create(['modifier_group_id' => $this->size->id, 'name' => 'Medium', 'extra_price' => 0]);
        $this->mod['large'] = Modifier::create(['modifier_group_id' => $this->size->id, 'name' => 'Large', 'extra_price' => 10]);

        // الإضافات: اختيارية، بحد أقصى 2
        $this->extras = ModifierGroup::create(['menu_item_id' => $this->item->id, 'name' => 'Extras', 'is_required' => false, 'max_selections' => 2]);
        $this->mod['cheese'] = Modifier::create(['modifier_group_id' => $this->extras->id, 'name' => 'Cheese', 'extra_price' => 3]);
        $this->mod['garlic'] = Modifier::create(['modifier_group_id' => $this->extras->id, 'name' => 'Garlic', 'extra_price' => 2]);
        $this->mod['olives'] = Modifier::create(['modifier_group_id' => $this->extras->id, 'name' => 'Olives', 'extra_price' => 1]);
        $this->mod['sold_out'] = Modifier::create(['modifier_group_id' => $this->extras->id, 'name' => 'Sold out', 'extra_price' => 1, 'is_available' => false]);
    }

    private function order(array $modifierKeys)
    {
        $ids = array_map(fn ($k) => $this->mod[$k]->id, $modifierKeys);

        return $this->placeOrder([['menu_item_id' => $this->item->id, 'quantity' => 1, 'modifiers' => $ids]]);
    }

    public function test_required_group_must_have_a_selection(): void
    {
        $this->placeOrder([['menu_item_id' => $this->item->id, 'quantity' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('items');

        // اختار من الإضافات بس بدون الحجم الإلزامي
        $this->order(['cheese'])->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_valid_selection_is_priced_with_the_extras(): void
    {
        $res = $this->order(['large', 'cheese', 'garlic'])->assertCreated();

        $this->assertEquals(25.0, (float) $res->json('subtotal')); // 10 + 10 + 3 + 2
    }

    public function test_cannot_exceed_max_selections_of_a_group(): void
    {
        $this->order(['medium', 'cheese', 'garlic', 'olives'])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->order(['medium', 'large'])->assertStatus(422)->assertJsonValidationErrors('items'); // الحجم max = 1
    }

    public function test_unavailable_modifier_is_rejected(): void
    {
        $this->order(['medium', 'sold_out'])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_modifier_of_another_item_is_rejected(): void
    {
        $other = $this->makeMenuItem(['price' => 5]);
        $group = ModifierGroup::create(['menu_item_id' => $other->id, 'name' => 'Other', 'is_required' => false, 'max_selections' => 1]);
        $foreign = Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Foreign', 'extra_price' => 1]);

        $this->placeOrder([['menu_item_id' => $this->item->id, 'quantity' => 1, 'modifiers' => [$this->mod['medium']->id, $foreign->id]]])
            ->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_required_group_without_available_options_does_not_block_ordering(): void
    {
        $item = $this->makeMenuItem(['price' => 7]);
        $group = ModifierGroup::create(['menu_item_id' => $item->id, 'name' => 'Sauce', 'is_required' => true, 'max_selections' => 1]);
        Modifier::create(['modifier_group_id' => $group->id, 'name' => 'Gone', 'extra_price' => 1, 'is_available' => false]);

        $this->placeOrder([['menu_item_id' => $item->id, 'quantity' => 1]])->assertCreated();
    }

    public function test_duplicate_modifier_ids_count_once(): void
    {
        $res = $this->placeOrder([[
            'menu_item_id' => $this->item->id,
            'quantity' => 1,
            'modifiers' => [$this->mod['medium']->id, $this->mod['cheese']->id, $this->mod['cheese']->id],
        ]])->assertCreated();

        $this->assertEquals(13.0, (float) $res->json('subtotal')); // 10 + 0 + 3 (مرة وحدة)
    }
}
