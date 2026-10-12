<?php

namespace Tests\Feature;

use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
    }

    private function table(string $number = 'T1'): RestaurantTable
    {
        return RestaurantTable::create(['table_number' => $number, 'capacity' => 4, 'status' => 'available']);
    }

    private function book(?int $tableId, string $time, array $extra = [])
    {
        return $this->postJson('/api/reservations', array_merge([
            'table_id' => $tableId,
            'customer_name' => 'Guest',
            'customer_phone' => '0790000000',
            'party_size' => 2,
            'reservation_date' => now()->addDay()->toDateString(),
            'reservation_time' => $time,
        ], $extra));
    }

    public function test_same_table_within_two_hours_is_rejected(): void
    {
        $table = $this->table();

        $this->book($table->id, '19:00')->assertCreated();
        $this->book($table->id, '20:30')->assertStatus(422)->assertJsonValidationErrors('table_id');
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_same_table_two_hours_apart_is_allowed(): void
    {
        $table = $this->table();

        $this->book($table->id, '19:00')->assertCreated();
        $this->book($table->id, '21:00')->assertCreated();
    }

    public function test_cancelled_reservation_does_not_block_the_table(): void
    {
        $table = $this->table();

        $id = $this->book($table->id, '19:00')->assertCreated()->json('id');
        $this->postJson("/api/reservations/{$id}/cancel", ['reason' => 'test'])->assertOk();

        $this->book($table->id, '19:30')->assertCreated();
    }

    public function test_cannot_book_in_the_past(): void
    {
        $this->book(null, '19:00', ['reservation_date' => now()->subDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('reservation_date');
    }

    /** نثبّت الوقت 18:30 ونحجز اليوم 19:00: الحجز ضمن نافذة مسك الطاولة */
    private function freezeAt1830AndBookToday(RestaurantTable $table): int
    {
        $this->travelTo(now()->setTime(18, 30));

        return $this->book($table->id, '19:00', ['reservation_date' => now()->toDateString()])
            ->assertCreated()->json('id');
    }

    public function test_reservation_status_flow_updates_table(): void
    {
        $table = $this->table();
        $id = $this->freezeAt1830AndBookToday($table);

        // لازم تتأكد قبل ما تكتمل
        $this->postJson("/api/reservations/{$id}/complete")->assertStatus(422);

        $this->postJson("/api/reservations/{$id}/confirm")->assertOk();
        $this->assertSame('reserved', $table->fresh()->status);

        // ما بتتأكد مرتين
        $this->postJson("/api/reservations/{$id}/confirm")->assertStatus(422);

        $this->postJson("/api/reservations/{$id}/complete")->assertOk();
        $this->assertSame('occupied', $table->fresh()->status);

        // المكتمل ما بينلغي
        $this->postJson("/api/reservations/{$id}/cancel")->assertStatus(422);
    }

    public function test_cancel_releases_a_reserved_table(): void
    {
        $table = $this->table();
        $id = $this->freezeAt1830AndBookToday($table);
        $this->postJson("/api/reservations/{$id}/confirm")->assertOk();
        $this->assertSame('reserved', $table->fresh()->status);

        $this->postJson("/api/reservations/{$id}/cancel")->assertOk();

        $this->assertSame('available', $table->fresh()->status);
    }

    public function test_confirming_a_future_reservation_does_not_lock_the_table_now(): void
    {
        $table = $this->table();
        $id = $this->book($table->id, '19:00')->assertCreated()->json('id'); // بكرا

        $this->postJson("/api/reservations/{$id}/confirm")->assertOk()->assertJsonPath('status', 'confirmed');

        $this->assertSame('available', $table->fresh()->status);
    }

    public function test_sync_command_reserves_the_table_when_the_reservation_gets_close(): void
    {
        $table = $this->table();
        $this->travelTo(now()->setTime(10, 0));
        $id = $this->book($table->id, '19:00', ['reservation_date' => now()->toDateString()])->assertCreated()->json('id');
        $this->postJson("/api/reservations/{$id}/confirm")->assertOk();
        $this->assertSame('available', $table->fresh()->status);

        $this->travelTo(now()->setTime(18, 30));
        $this->artisan('reservations:sync-tables')->assertSuccessful();

        $this->assertSame('reserved', $table->fresh()->status);
    }

    public function test_cancel_keeps_table_reserved_while_another_confirmed_reservation_holds_it(): void
    {
        $table = $this->table();
        $this->travelTo(now()->setTime(10, 0));
        $today = ['reservation_date' => now()->toDateString()];

        $first = $this->book($table->id, '19:00', $today)->assertCreated()->json('id');
        $second = $this->book($table->id, '21:00', $today)->assertCreated()->json('id');
        $this->postJson("/api/reservations/{$first}/confirm")->assertOk();
        $this->postJson("/api/reservations/{$second}/confirm")->assertOk();

        // 20:30: الحجزين الاثنين ضمن نافذة مسك الطاولة
        $this->travelTo(now()->setTime(20, 30));
        $this->artisan('reservations:sync-tables')->assertSuccessful();
        $this->assertSame('reserved', $table->fresh()->status);

        $this->postJson("/api/reservations/{$first}/cancel")->assertOk();
        $this->assertSame('reserved', $table->fresh()->status); // الحجز التاني لسا ماسكها

        $this->postJson("/api/reservations/{$second}/cancel")->assertOk();
        $this->assertSame('available', $table->fresh()->status);
    }

    public function test_party_size_cannot_exceed_table_capacity(): void
    {
        $table = $this->table(); // سعة 4

        $this->book($table->id, '19:00', ['party_size' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('party_size');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_cannot_book_a_time_that_already_passed_today(): void
    {
        $this->travelTo(now()->setTime(18, 30));

        $this->book(null, '17:00', ['reservation_date' => now()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('reservation_time');
    }

    public function test_generic_update_cannot_bypass_the_status_flow(): void
    {
        $table = $this->table();
        $id = $this->book($table->id, '19:00')->assertCreated()->json('id');

        // pending -> completed غير مسموح حتى عن طريق PUT
        $this->putJson("/api/reservations/{$id}", ['status' => 'completed'])->assertStatus(422);
        $this->assertSame('pending', \App\Models\Reservation::find($id)->status->value);

        $this->putJson("/api/reservations/{$id}", ['status' => 'confirmed'])->assertOk()->assertJsonPath('status', 'confirmed');
        $this->putJson("/api/reservations/{$id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('status', 'cancelled');

        // حجز ملغي ما بينعدّل موعده
        $this->putJson("/api/reservations/{$id}", ['reservation_time' => '20:00'])->assertStatus(422);
    }

    public function test_update_can_clear_the_table(): void
    {
        $table = $this->table();
        $id = $this->book($table->id, '19:00')->assertCreated()->json('id');

        $this->putJson("/api/reservations/{$id}", ['table_id' => null])->assertOk()->assertJsonPath('table_id', null);
    }

    public function test_no_show_releases_the_table(): void
    {
        $table = $this->table();
        $id = $this->freezeAt1830AndBookToday($table);
        $this->postJson("/api/reservations/{$id}/confirm")->assertOk();
        $this->assertSame('reserved', $table->fresh()->status);

        $this->postJson("/api/reservations/{$id}/no-show")->assertOk()->assertJsonPath('status', 'no_show');

        $this->assertSame('available', $table->fresh()->status);
    }
}
