<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function orderBody(): array
    {
        $item = $this->makeMenuItem(['price' => 10]);

        return ['order_type' => 'takeaway', 'items' => [['menu_item_id' => $item->id, 'quantity' => 1]]];
    }

    public function test_same_key_creates_the_order_once_and_replays_the_response(): void
    {
        $this->actingAsRole('admin');
        $body = $this->orderBody();
        $headers = ['Idempotency-Key' => 'order-key-0001'];

        $first = $this->withHeaders($headers)->postJson('/api/orders', $body)->assertCreated();
        $second = $this->withHeaders($headers)->postJson('/api/orders', $body)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame($first->json('order_number'), $second->json('order_number'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_same_key_creates_the_payment_once(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $body = ['order_id' => $order->id, 'method' => 'cash', 'amount' => 100];
        $headers = ['Idempotency-Key' => 'pay-key-00001'];

        $this->withHeaders($headers)->postJson('/api/payments', $body)->assertCreated();
        // بدون الـ Idempotency هاي المحاولة التانية كانت رح ترجع 422 (تجاوز المتبقي) أو تكرّر الدفعة
        $this->withHeaders($headers)->postJson('/api/payments', $body)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_without_a_key_requests_behave_as_before(): void
    {
        $this->actingAsRole('admin');
        $body = $this->orderBody();

        $this->postJson('/api/orders', $body)->assertCreated();
        $this->postJson('/api/orders', $body)->assertCreated();

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_same_key_with_a_different_body_is_rejected(): void
    {
        $this->actingAsRole('admin');
        $headers = ['Idempotency-Key' => 'order-key-0002'];

        $this->withHeaders($headers)->postJson('/api/orders', $this->orderBody())->assertCreated();
        $this->withHeaders($headers)->postJson('/api/orders', $this->orderBody())->assertStatus(422);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_a_failed_request_releases_the_key_so_a_corrected_retry_works(): void
    {
        $this->actingAsRole('admin');
        $order = $this->makeOrder(100);
        $headers = ['Idempotency-Key' => 'pay-key-00002'];

        // أكبر من المتبقي = 422، المفتاح لازم ينحرّر
        $this->withHeaders($headers)->postJson('/api/payments', [
            'order_id' => $order->id, 'method' => 'cash', 'amount' => 500,
        ])->assertStatus(422);

        $this->assertDatabaseCount('idempotency_keys', 0);

        $this->withHeaders($headers)->postJson('/api/payments', [
            'order_id' => $order->id, 'method' => 'cash', 'amount' => 100,
        ])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    }

    public function test_an_in_progress_request_with_the_same_key_gets_409(): void
    {
        $user = $this->actingAsRole('admin');
        $body = $this->orderBody();

        // طلب أول "لسا شغّال" (محجوز بدون completed_at)
        \DB::table('idempotency_keys')->insert([
            'user_id' => $user->id, 'key' => 'order-key-0003', 'method' => 'POST', 'path' => '/api/orders',
            'request_hash' => hash('sha256', 'POST|/api/orders|' . json_encode($body)),
            'locked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // postJson بيرمّز الجسم بـ json_encode نفسه، فالـ hash بيطابق الطلب المحجوز
        $this->withHeaders(['Idempotency-Key' => 'order-key-0003'])
            ->postJson('/api/orders', $body)
            ->assertStatus(409);
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $this->actingAsRole('admin');
        $this->withHeaders(['Idempotency-Key' => 'shared-key-001'])->postJson('/api/orders', $this->orderBody())->assertCreated();

        $this->actingAsRole('manager');
        $this->withHeaders(['Idempotency-Key' => 'shared-key-001'])->postJson('/api/orders', $this->orderBody())
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_malformed_key_is_rejected(): void
    {
        $this->actingAsRole('admin');

        $this->withHeaders(['Idempotency-Key' => 'short'])->postJson('/api/orders', $this->orderBody())->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_prune_command_removes_old_keys_only(): void
    {
        $user = $this->actingAsRole('admin');
        $row = fn (string $key, $at) => [
            'user_id' => $user->id, 'key' => $key, 'method' => 'POST', 'path' => '/api/orders',
            'request_hash' => str_repeat('a', 64), 'created_at' => $at, 'updated_at' => $at,
        ];
        \DB::table('idempotency_keys')->insert([
            $row('old-key-0001', now()->subHours(30)),
            $row('new-key-0001', now()->subHour()),
        ]);

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertDatabaseMissing('idempotency_keys', ['key' => 'old-key-0001']);
        $this->assertDatabaseHas('idempotency_keys', ['key' => 'new-key-0001']);
    }
}
