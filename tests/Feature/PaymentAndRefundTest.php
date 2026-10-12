<?php

namespace Tests\Feature;

use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class PaymentAndRefundTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function pay($order, float $amount, string $method = 'cash')
    {
        return $this->postJson('/api/payments', [
            'order_id' => $order->id, 'method' => $method, 'amount' => $amount,
        ]);
    }

    // ---------------- الدفعات ----------------

    public function test_payment_status_follows_payments(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $this->pay($order, 40)->assertCreated();
        $this->assertSame('partial', $order->fresh()->payment_status);

        $this->pay($order, 60)->assertCreated();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_cannot_overpay_an_order(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $this->pay($order, 100.01)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cannot_create_a_payment_directly_as_refunded(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);

        $this->postJson('/api/payments', [
            'order_id' => $order->id, 'method' => 'cash', 'amount' => 10, 'status' => 'refunded',
        ])->assertStatus(422);
    }

    public function test_cannot_pay_a_cancelled_order(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100, ['status' => 'cancelled']);

        $this->pay($order, 10)->assertStatus(422);
    }

    public function test_confirmed_payment_amount_and_status_are_immutable(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $id = $this->pay($order, 100)->json('id');

        $this->putJson("/api/payments/{$id}", ['amount' => 1])->assertStatus(422);
        $this->putJson("/api/payments/{$id}", ['status' => 'paid'])->assertStatus(422);
        $this->putJson("/api/payments/{$id}", ['method' => 'card'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_confirmed_payment_cannot_be_deleted_only_pending_ones(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $paid = $this->pay($order, 50)->json('id');
        $pending = $this->postJson('/api/payments', [
            'order_id' => $order->id, 'method' => 'card', 'amount' => 50, 'status' => 'pending',
        ])->json('id');

        $this->deleteJson("/api/payments/{$paid}")->assertStatus(422);
        $this->deleteJson("/api/payments/{$pending}")->assertOk();
    }

    // ---------------- الاسترجاع ----------------

    public function test_refund_cannot_exceed_amount_paid(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $this->pay($order, 100);

        $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 100.01, 'reason' => 'x'])
            ->assertStatus(422);
    }

    public function test_pending_refunds_reserve_the_amount(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $this->pay($order, 100);

        $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 60, 'reason' => 'a'])->assertCreated();
        // قبل الإصلاح: الطلب التاني كان يعدي لأن المعلّق ما كان يتحسب
        $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 60, 'reason' => 'b'])->assertStatus(422);
        $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 40, 'reason' => 'c'])->assertCreated();
    }

    public function test_full_refund_marks_order_refunded(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $this->pay($order, 100);

        $id = $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 100, 'reason' => 'x'])->json('id');
        $this->postJson("/api/refunds/{$id}/approve")->assertOk();

        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_refunding_one_of_two_payments_does_not_double_count(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $p1 = $this->pay($order, 50)->json('id');
        $this->pay($order, 50);

        $r1 = $this->postJson('/api/refunds', [
            'order_id' => $order->id, 'payment_id' => $p1, 'amount' => 50, 'reason' => 'x',
        ])->json('id');
        $this->postJson("/api/refunds/{$r1}/approve")->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $p1, 'status' => 'refunded']);
        $this->assertSame('partial', $order->fresh()->payment_status);

        // لسا 50 مقبوضة ومتاحة للاسترجاع (قبل الإصلاح كان المتاح 0)
        $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 50.01, 'reason' => 'y'])->assertStatus(422);
        $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 50, 'reason' => 'y'])->assertCreated();
    }

    public function test_refund_payment_must_belong_to_the_same_order(): void
    {
        $this->actingAsRole('admin');
        $a = $this->makeOrder(100);
        $b = $this->makeOrder(100);
        $this->pay($a, 100);
        $paymentOfB = $this->pay($b, 100)->json('id');

        $this->postJson('/api/refunds', [
            'order_id' => $a->id, 'payment_id' => $paymentOfB, 'amount' => 10, 'reason' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('payment_id');
    }

    public function test_refund_cannot_be_approved_twice(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $this->pay($order, 100);
        $id = $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 30, 'reason' => 'x'])->json('id');

        $this->postJson("/api/refunds/{$id}/approve")->assertOk();
        $this->postJson("/api/refunds/{$id}/approve")->assertStatus(422);
        $this->assertSame(1, Refund::where('status', 'approved')->count());
    }

    public function test_cashier_can_request_but_not_approve_refund(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $this->pay($order, 100);

        $this->actingAsRole('cashier');
        $id = $this->postJson('/api/refunds', ['order_id' => $order->id, 'amount' => 10, 'reason' => 'x'])
            ->assertCreated()->json('id');
        $this->postJson("/api/refunds/{$id}/approve")->assertForbidden();
    }
}
