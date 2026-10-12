<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CashDrawer;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class CashDrawerTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow("2026-10-07 {$time}:00");
    }

    /** دفعة مباشرة بالـ DB بوقت محدد (بدون API) */
    private function payment(User $by, string $method, float $amount, ?string $time = null): Payment
    {
        if ($time) {
            $this->at($time);
        }

        return Payment::create([
            'order_id' => $this->makeOrder(500)->id, 'method' => $method, 'amount' => $amount,
            'status' => 'paid', 'received_by' => $by->id,
        ]);
    }

    public function test_full_flow_expected_cash_and_closing(): void
    {
        $cashier = $this->actingAsRole('cashier');
        $other = User::create(['name' => 'Other', 'email' => 'o@t.local', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true]);

        $this->payment($cashier, 'cash', 30, '09:00');       // قبل الفتح: مستثناة
        $this->at('10:00');
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 100])->assertCreated();

        $this->at('10:30');
        $cash = $this->payment($cashier, 'cash', 50);
        $this->payment($cashier, 'card', 20);                 // بطاقة: مش بالمتوقع
        $this->payment($other, 'cash', 40);                   // كاشير تاني: مستثناة

        // استرجاع نقدي معتمد طلبه هالكاشير = بينقص من الصندوق
        $this->at('11:00');
        Refund::create([
            'order_id' => $cash->order_id, 'payment_id' => $cash->id, 'amount' => 10, 'reason' => 'x',
            'status' => 'approved', 'requested_by' => $cashier->id, 'processed_by' => $cashier->id, 'processed_at' => now(),
        ]);
        // استرجاع على بطاقة: ما بيأثر
        $card = Payment::where('method', 'card')->first();
        Refund::create([
            'order_id' => $card->order_id, 'payment_id' => $card->id, 'amount' => 5, 'reason' => 'x',
            'status' => 'approved', 'requested_by' => $cashier->id, 'processed_by' => $cashier->id, 'processed_at' => now(),
        ]);

        $cur = $this->getJson('/api/cash-drawers/current')->assertOk();
        $this->assertEquals(50, $cur->json('drawer.cash_sales'));
        $this->assertEquals(10, $cur->json('drawer.cash_refunds'));
        $this->assertEquals(140, $cur->json('drawer.expected_cash')); // 100 + 50 - 10
        $this->assertEquals(20, $cur->json('drawer.by_method.card.total'));

        $id = $cur->json('drawer.id');
        $this->at('18:00');
        // closed_by لازم يضل الـ id (مش object): الـ relation اسمها closer عشان ما تطمس العمود
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 140])
            ->assertOk()
            ->assertJsonPath('difference', '0.00')
            ->assertJsonPath('closed_by', $cashier->id)
            ->assertJsonPath('closer.id', $cashier->id);

        $drawer = CashDrawer::find($id);
        $this->assertFalse($drawer->isOpen());
        $this->assertEquals(140, $drawer->expected_cash);
        $this->assertEquals(0, AppNotification::where('type', 'drawer')->count()); // ما في فرق = ما في إشعار

        $this->getJson('/api/cash-drawers/current')->assertOk()->assertJsonPath('drawer', null);
    }

    public function test_difference_requires_a_note_and_notifies_managers(): void
    {
        $manager = $this->actingAsRole('manager');
        $cashier = $this->actingAsRole('cashier');
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 100]);
        $id = CashDrawer::first()->id;
        $this->payment($cashier, 'cash', 50, '10:00');

        // المتوقع 150، عدّ 140: عجز 10 بدون ملاحظة = مرفوض والصندوق بيضل مفتوح
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 140])
            ->assertStatus(422)->assertJsonValidationErrors('notes');
        $this->assertTrue(CashDrawer::find($id)->isOpen());

        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 140, 'notes' => 'ورقة ناقصة'])
            ->assertOk()->assertJsonPath('difference', '-10.00');

        $this->assertDatabaseHas('app_notifications', ['user_id' => $manager->id, 'type' => 'drawer']);
        $this->assertSame(0, AppNotification::where('user_id', $cashier->id)->where('type', 'drawer')->count());
    }

    public function test_overage_is_recorded_as_positive_difference(): void
    {
        $cashier = $this->actingAsRole('cashier');
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 100]);
        $id = CashDrawer::first()->id;

        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 103.5, 'notes' => 'بقشيش بالصندوق'])
            ->assertOk()->assertJsonPath('difference', '3.50');
    }

    public function test_only_one_open_drawer_per_user_and_no_double_close(): void
    {
        $this->actingAsRole('cashier');

        $this->postJson('/api/cash-drawers/open', ['opening_float' => 50])->assertCreated();
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 50])->assertStatus(422)->assertJsonValidationErrors('drawer');

        $id = CashDrawer::first()->id;
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 50])->assertOk();
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 50])->assertStatus(422);

        // بعد الإغلاق بيقدر يفتح صندوق جديد
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 10])->assertCreated();
        $this->assertSame(2, CashDrawer::count());
    }

    public function test_open_is_idempotent_with_key(): void
    {
        $this->actingAsRole('cashier');
        $h = ['Idempotency-Key' => 'drawer-open-0001'];

        $a = $this->withHeaders($h)->postJson('/api/cash-drawers/open', ['opening_float' => 50])->assertCreated();
        $b = $this->withHeaders($h)->postJson('/api/cash-drawers/open', ['opening_float' => 50])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, CashDrawer::count());
    }

    public function test_ownership_and_visibility(): void
    {
        $owner = $this->actingAsRole('cashier');
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 50]);
        $id = CashDrawer::first()->id;

        // كاشير تاني: ما بيشوف ولا بيسكّر صندوق غيره
        $this->actingAsRole('cashier');
        $this->getJson("/api/cash-drawers/{$id}")->assertForbidden();
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 50])->assertForbidden();
        $this->getJson('/api/cash-drawers')->assertOk()->assertJsonCount(0, 'data');

        // المدير بيشوف الكل وبيقدر يسكّر عن غيره (الإغلاق بيتسجّل باسمه)
        $manager = $this->actingAsRole('manager');
        $this->getJson('/api/cash-drawers')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 50])->assertOk();
        $this->assertSame($manager->id, CashDrawer::find($id)->closed_by);
        $this->assertSame($owner->id, CashDrawer::find($id)->user_id);
    }

    public function test_roles_without_drawer_permission_are_refused(): void
    {
        $this->actingAsRole('waiter');

        $this->getJson('/api/cash-drawers/current')->assertForbidden();
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 10])->assertForbidden();
    }

    public function test_opening_float_is_validated(): void
    {
        $this->actingAsRole('cashier');

        $this->postJson('/api/cash-drawers/open', [])->assertStatus(422);
        $this->postJson('/api/cash-drawers/open', ['opening_float' => -5])->assertStatus(422);
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 0])->assertCreated();
    }

    public function test_api_payment_records_who_received_it(): void
    {
        $cashier = $this->actingAsRole('cashier');
        $order = $this->makeOrder(100);

        $res = $this->postJson('/api/payments', ['order_id' => $order->id, 'method' => 'cash', 'amount' => 100])->assertCreated();

        $this->assertSame($cashier->id, Payment::find($res->json('id'))->received_by);
    }

    public function test_cash_method_of_a_payment_in_a_closed_drawer_cannot_be_changed(): void
    {
        $cashier = $this->actingAsRole('cashier');
        $this->at('10:00');
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 0]);
        $payment = $this->payment($cashier, 'cash', 50, '10:30');
        $id = CashDrawer::first()->id;
        $this->at('12:00');
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 50])->assertOk();

        $this->actingAsRole('admin');
        $this->putJson("/api/payments/{$payment->id}", ['method' => 'card'])
            ->assertStatus(422)->assertJsonValidationErrors('method');
        $this->assertSame('cash', $payment->fresh()->method);

        // دفعة بدون صندوق مسكّر (أو تغيير ما بيمس الكاش) بتضل مسموحة
        $free = $this->payment($cashier, 'card', 20, '13:00');
        $this->putJson("/api/payments/{$free->id}", ['method' => 'online'])->assertOk();
    }

    public function test_drawer_is_linked_to_the_open_shift(): void
    {
        $cashier = $this->actingAsRole('cashier');
        $shift = $cashier->shifts()->create(['clock_in' => now()->subHour()]);

        $this->postJson('/api/cash-drawers/open', ['opening_float' => 10])->assertCreated();

        $this->assertSame($shift->id, CashDrawer::first()->shift_id);
    }

    public function test_stale_open_drawer_is_flagged(): void
    {
        $cashier = $this->actingAsRole('cashier');
        $this->postJson('/api/cash-drawers/open', ['opening_float' => 10]);
        Carbon::setTestNow('2026-10-09 09:00:00'); // يومين بعدين

        $this->getJson('/api/cash-drawers/current')->assertOk()->assertJsonPath('drawer.is_stale', true);
    }
}
