<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class ForcePasswordChangeTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function asToken(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function createStaffViaAdmin(string $password = 'temp1234'): User
    {
        $this->actingAsRole('admin');

        $id = $this->postJson('/api/users', [
            'name' => 'New Waiter',
            'email' => 'waiter.new@test.local',
            'password' => $password,
            'role' => 'waiter',
        ])->assertCreated()->json('id');

        $this->app['auth']->forgetGuards();

        return User::findOrFail($id);
    }

    private function loginToken(string $email, string $password): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/login', ['email' => $email, 'password' => $password])
            ->assertOk()->json('token');
    }

    public function test_account_created_by_admin_must_change_password(): void
    {
        $user = $this->createStaffViaAdmin();

        $this->assertTrue($user->must_change_password);
    }

    public function test_flagged_user_can_login_but_is_blocked_from_the_api(): void
    {
        $user = $this->createStaffViaAdmin();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'temp1234'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        $token = $this->loginToken($user->email, 'temp1234');

        $this->asToken($token)->getJson('/api/orders')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');
        $this->asToken($token)->getJson('/api/categories')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_change_required');

        // المسموح فقط: me و change-password و logout
        $this->asToken($token)->getJson('/api/me')->assertOk();
    }

    public function test_changing_password_frees_the_user_and_persists(): void
    {
        $user = $this->createStaffViaAdmin();
        $token = $this->loginToken($user->email, 'temp1234');

        $this->asToken($token)->postJson('/api/change-password', [
            'current_password' => 'temp1234',
            'password' => 'my-own-pass-99',
            'password_confirmation' => 'my-own-pass-99',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('my-own-pass-99', $fresh->password));

        // نفس التوكن صار يشتغل عادي
        $this->asToken($token)->getJson('/api/categories')->assertOk();

        // وما بيتجبر مرة ثانية عند الدخول الجاي
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'my-own-pass-99'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false);
    }

    public function test_new_password_must_differ_from_the_temporary_one(): void
    {
        $user = $this->createStaffViaAdmin();
        $token = $this->loginToken($user->email, 'temp1234');

        $this->asToken($token)->postJson('/api/change-password', [
            'current_password' => 'temp1234',
            'password' => 'temp1234',
            'password_confirmation' => 'temp1234',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_admin_reset_of_another_users_password_forces_change_again(): void
    {
        $this->actingAsRole('admin');
        $user = User::create([
            'name' => 'Old Cashier', 'email' => 'cashier.old@test.local',
            'password' => 'secret123', 'role' => 'cashier', 'is_active' => true,
        ]);
        // fresh(): القيمة الافتراضية (false) موجودة بقاعدة البيانات، مش على الكائن يلي لسا مسوّيه بالذاكرة
        $this->assertFalse($user->fresh()->must_change_password);

        $this->postJson("/api/users/{$user->id}/reset-password", ['password' => 'reset1234'])->assertOk();

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_admin_resetting_own_password_is_not_forced(): void
    {
        $admin = $this->actingAsRole('admin');

        $this->postJson("/api/users/{$admin->id}/reset-password", ['password' => 'mine12345'])->assertOk();

        $this->assertFalse($admin->fresh()->must_change_password);
    }

    public function test_existing_users_are_not_affected(): void
    {
        $user = User::create([
            'name' => 'Existing', 'email' => 'existing@test.local',
            'password' => 'secret123', 'role' => 'cashier', 'is_active' => true,
        ]);

        $token = $this->loginToken($user->email, 'secret123');

        $this->asToken($token)->getJson('/api/categories')->assertOk();
    }

    public function test_client_cannot_set_the_flag_through_the_api(): void
    {
        $this->actingAsRole('admin');

        $id = $this->postJson('/api/users', [
            'name' => 'X', 'email' => 'x@test.local', 'password' => 'temp1234',
            'role' => 'waiter', 'must_change_password' => false,
        ])->assertCreated()->json('id');

        $this->assertTrue(User::findOrFail($id)->must_change_password);
    }
}
