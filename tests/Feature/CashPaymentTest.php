<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class CashPaymentTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function pay($order, array $extra = [])
    {
        return $this->postJson('/api/payments', array_merge([
            'order_id' => $order->id, 'method' => 'cash', 'amount' => 60,
        ], $extra));
    }

    public function test_cash_records_tendered_amount_and_change(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $res = $this->pay($order, ['tendered_amount' => 100])->assertCreated();

        $this->assertEquals(60, $res->json('amount'));
        $this->assertEquals(100, $res->json('tendered_amount'));
        $this->assertEquals(40, $res->json('change_amount'));
        // المبلغ المحسوب على الطلب هو 60 (مش المقبوض): الطلب لسا partial
        $this->assertSame('partial', $order->fresh()->payment_status);
    }

    public function test_cash_without_tendered_defaults_to_exact_amount(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $res = $this->pay($order)->assertCreated();

        $this->assertEquals(60, $res->json('tendered_amount'));
        $this->assertEquals(0, $res->json('change_amount'));
    }

    public function test_tendered_less_than_amount_is_rejected(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $this->pay($order, ['tendered_amount' => 50])->assertStatus(422)->assertJsonValidationErrors('tendered_amount');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_change_is_computed_in_cents_without_float_drift(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $res = $this->pay($order, ['amount' => 0.1, 'tendered_amount' => 0.3])->assertCreated();

        $this->assertEquals(0.2, $res->json('change_amount'));
    }

    public function test_card_payment_cannot_have_tendered_amount(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $this->pay($order, ['method' => 'card', 'tendered_amount' => 100])->assertStatus(422)->assertJsonValidationErrors('tendered_amount');

        $res = $this->pay($order, ['method' => 'card'])->assertCreated();
        $this->assertNull($res->json('tendered_amount'));
        $this->assertNull($res->json('change_amount'));
    }

    public function test_pending_cash_payment_gets_tendered_when_confirmed(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $payment = $this->pay($order, ['status' => 'pending'])->assertCreated();
        $this->assertNull($payment->json('tendered_amount'));

        $this->putJson('/api/payments/' . $payment->json('id'), ['status' => 'paid', 'tendered_amount' => 80])
            ->assertOk()
            ->assertJsonPath('change_amount', '20.00');
    }

    public function test_tendered_cannot_be_edited_on_a_confirmed_payment(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $payment = $this->pay($order, ['tendered_amount' => 100])->assertCreated();

        $this->putJson('/api/payments/' . $payment->json('id'), ['tendered_amount' => 200])->assertStatus(422);
        $this->assertEquals(100, Payment::find($payment->json('id'))->tendered_amount);
    }

    public function test_switching_method_resets_cash_fields(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $id = $this->pay($order, ['tendered_amount' => 100])->json('id');

        $this->putJson("/api/payments/{$id}", ['method' => 'card'])->assertOk();
        $this->assertNull(Payment::find($id)->tendered_amount);

        $this->putJson("/api/payments/{$id}", ['method' => 'cash'])->assertOk();
        $p = Payment::find($id);
        $this->assertEquals(60, $p->tendered_amount);
        $this->assertEquals(0, $p->change_amount);
    }
}
