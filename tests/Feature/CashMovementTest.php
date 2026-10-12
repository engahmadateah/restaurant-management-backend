<?php

namespace Tests\Feature;

use App\Models\CashDrawer;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class CashMovementTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    /** كاشير بصندوق مفتوح برصيد بداية محدد، بيرجّع [الكاشير، id الصندوق] */
    private function openDrawer(float $float = 100): array
    {
        $cashier = $this->actingAsRole('cashier');
        $this->postJson('/api/cash-drawers/open', ['opening_float' => $float])->assertCreated();

        return [$cashier, CashDrawer::first()->id];
    }

    private function move(int $drawerId, string $type, float $amount, string $reason = 'سبب', array $extra = [])
    {
        return $this->postJson("/api/cash-drawers/{$drawerId}/movements", array_merge([
            'type' => $type, 'amount' => $amount, 'reason' => $reason,
        ], $extra));
    }

    public function test_cash_expense_lowers_expected_cash_and_creates_an_expense_record(): void
    {
        [$cashier, $id] = $this->openDrawer(100);

        $res = $this->move($id, 'expense', 30, 'دفعت للمورّد', ['category' => 'supplies'])->assertCreated();
        $this->assertSame('expense', $res->json('type'));
        $this->assertEquals(30, $res->json('amount'));

        $expense = Expense::first();
        $this->assertSame('دفعت للمورّد', $expense->title);
        $this->assertSame('supplies', $expense->category);
        $this->assertEquals(30, $expense->amount);
        $this->assertSame($cashier->id, $expense->created_by);
        $this->assertSame($expense->id, CashMovement::first()->expense_id);

        $cur = $this->getJson('/api/cash-drawers/current')->assertOk();
        $this->assertEquals(30, $cur->json('drawer.cash_expenses'));
        $this->assertEquals(70, $cur->json('drawer.expected_cash')); // 100 - 30
    }

    public function test_expense_category_defaults_to_other_and_is_validated(): void
    {
        [, $id] = $this->openDrawer(100);

        $this->move($id, 'expense', 5)->assertCreated();
        $this->assertSame('other', Expense::first()->category);

        $this->move($id, 'expense', 5, 'x', ['category' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_expected_cash_math_with_sales_expense_withdrawal_and_deposit(): void
    {
        [$cashier, $id] = $this->openDrawer(100);

        Payment::create([
            'order_id' => $this->makeOrder(500)->id, 'method' => 'cash', 'amount' => 50,
            'status' => 'paid', 'received_by' => $cashier->id,
        ]);

        $this->move($id, 'expense', 20)->assertCreated();
        $this->move($id, 'withdrawal', 40)->assertCreated();
        $this->move($id, 'deposit', 10)->assertCreated();

        $cur = $this->getJson('/api/cash-drawers/current')->assertOk();
        $this->assertEquals(50, $cur->json('drawer.cash_sales'));
        $this->assertEquals(20, $cur->json('drawer.cash_expenses'));
        $this->assertEquals(40, $cur->json('drawer.cash_withdrawals'));
        $this->assertEquals(10, $cur->json('drawer.cash_deposits'));
        $this->assertEquals(100, $cur->json('drawer.expected_cash')); // 100 + 50 - 20 - 40 + 10

        // الإغلاق بيخزّن لقطة المجاميع
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 100])
            ->assertOk()
            ->assertJsonPath('difference', '0.00');

        $drawer = CashDrawer::find($id);
        $this->assertEquals(20, $drawer->cash_expenses);
        $this->assertEquals(40, $drawer->cash_withdrawals);
        $this->assertEquals(10, $drawer->cash_deposits);
        $this->assertEquals(100, $drawer->expected_cash);
    }

    public function test_cannot_take_out_more_than_the_drawer_holds(): void
    {
        [, $id] = $this->openDrawer(50);

        $this->move($id, 'withdrawal', 60)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->move($id, 'expense', 50.01)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, Expense::count());

        // بالضبط يلي بالصندوق مسموح، والإيداع ما إله سقف
        $this->move($id, 'withdrawal', 50)->assertCreated();
        $this->move($id, 'deposit', 500)->assertCreated();
    }

    public function test_validation(): void
    {
        [, $id] = $this->openDrawer(100);

        $this->postJson("/api/cash-drawers/{$id}/movements", [])->assertStatus(422);
        $this->move($id, 'gift', 5)->assertStatus(422)->assertJsonValidationErrors('type');
        $this->move($id, 'deposit', 0)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->move($id, 'deposit', -3)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/cash-drawers/{$id}/movements", ['type' => 'deposit', 'amount' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_closed_drawer_refuses_new_movements(): void
    {
        [, $id] = $this->openDrawer(100);
        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 100])->assertOk();

        $this->move($id, 'deposit', 10)->assertStatus(422)->assertJsonValidationErrors('drawer');
        $this->assertSame(0, CashMovement::count());
    }

    public function test_ownership_and_manager_access(): void
    {
        [$owner, $id] = $this->openDrawer(100);

        // كاشير تاني: ما بيشوف ولا بيضيف على صندوق غيره
        $this->actingAsRole('cashier');
        $this->getJson("/api/cash-drawers/{$id}/movements")->assertForbidden();
        $this->move($id, 'deposit', 10)->assertForbidden();

        // المدير بيضيف عن الكاشير والحركة بتتسجّل باسمه
        $manager = $this->actingAsRole('manager');
        $res = $this->move($id, 'deposit', 10)->assertCreated();
        $this->assertSame($manager->id, CashMovement::find($res->json('id'))->user_id);
        $this->assertSame($owner->id, CashDrawer::find($id)->user_id);

        $list = $this->getJson("/api/cash-drawers/{$id}/movements")->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertEquals(10, $list->json('totals.deposits'));
    }

    public function test_roles_without_drawer_permission_are_refused(): void
    {
        [, $id] = $this->openDrawer(100);

        $this->actingAsRole('waiter');
        $this->getJson("/api/cash-drawers/{$id}/movements")->assertForbidden();
        $this->move($id, 'deposit', 10)->assertForbidden();
    }

    public function test_only_manager_can_delete_and_it_removes_the_linked_expense(): void
    {
        [, $id] = $this->openDrawer(100);
        $movementId = $this->move($id, 'expense', 30, 'غلطة')->assertCreated()->json('id');
        $this->assertSame(1, Expense::count());

        // الكاشير ما بيحذف
        $this->deleteJson("/api/cash-drawers/{$id}/movements/{$movementId}")->assertForbidden();
        $this->assertSame(1, CashMovement::count());

        $this->actingAsRole('manager');
        $this->deleteJson("/api/cash-drawers/{$id}/movements/{$movementId}")->assertOk();

        $this->assertSame(0, CashMovement::count());
        $this->assertSame(0, Expense::count());
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => CashMovement::class, 'action' => 'deleted']);
    }

    public function test_cannot_delete_from_a_closed_drawer_or_a_foreign_drawer(): void
    {
        [, $id] = $this->openDrawer(100);
        $movementId = $this->move($id, 'deposit', 10)->assertCreated()->json('id');

        $other = CashDrawer::create([
            'user_id' => User::create(['name' => 'O', 'email' => 'o@t.local', 'password' => 'secret123', 'role' => 'cashier', 'is_active' => true])->id,
            'opening_float' => 0, 'opened_at' => now(),
        ]);

        $this->actingAsRole('manager');
        // الحركة مو تابعة للصندوق بالمسار
        $this->deleteJson("/api/cash-drawers/{$other->id}/movements/{$movementId}")->assertNotFound();

        $this->postJson("/api/cash-drawers/{$id}/close", ['counted_cash' => 110])->assertOk();
        $this->deleteJson("/api/cash-drawers/{$id}/movements/{$movementId}")
            ->assertStatus(422)->assertJsonValidationErrors('drawer');
        $this->assertSame(1, CashMovement::count());
    }

    public function test_store_is_idempotent_with_key(): void
    {
        [, $id] = $this->openDrawer(100);
        $h = ['Idempotency-Key' => 'cash-move-0001'];
        $body = ['type' => 'expense', 'amount' => 25, 'reason' => 'مورّد'];

        $a = $this->withHeaders($h)->postJson("/api/cash-drawers/{$id}/movements", $body)->assertCreated();
        $b = $this->withHeaders($h)->postJson("/api/cash-drawers/{$id}/movements", $body)
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(1, CashMovement::count());
        $this->assertSame(1, Expense::count());
    }

    public function test_z_report_includes_cash_movements(): void
    {
        [, $id] = $this->openDrawer(200);
        $this->move($id, 'expense', 20)->assertCreated();
        $this->move($id, 'withdrawal', 40)->assertCreated();
        $this->move($id, 'deposit', 10)->assertCreated();

        $this->actingAsRole('manager');
        $r = $this->getJson('/api/reports/z-report')->assertOk();

        $this->assertSame(3, $r->json('cash_movements.count'));
        $this->assertEquals(20, $r->json('cash_movements.expenses'));
        $this->assertEquals(40, $r->json('cash_movements.withdrawals'));
        $this->assertEquals(10, $r->json('cash_movements.deposits'));

        // الـ PDF لازم يتولّد بدون خطأ مع القسم الجديد
        $this->get('/api/reports/z-report/pdf', ['Accept' => 'application/pdf'])->assertOk();
    }
}
