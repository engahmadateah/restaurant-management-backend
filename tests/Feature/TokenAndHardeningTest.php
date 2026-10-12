<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesRestaurantData;
use Tests\TestCase;

class TokenAndHardeningTest extends TestCase
{
    use CreatesRestaurantData, RefreshDatabase;

    private function makeUser(string $role = 'cashier', string $password = 'secret123'): User
    {
        return User::create([
            'name' => $role, 'email' => $role . uniqid() . '@t.local', 'password' => $password,
            'role' => $role, 'is_active' => true,
        ]);
    }

    /** طلب حقيقي بتوكن Bearer (مش actingAs) مع تصفير كاش الـ guard بين الطلبات */
    private function asToken(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function login(User $user, string $password = 'secret123'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()->json('token');
    }

    // ---------------- التوكنات ----------------

    public function test_deactivated_user_loses_access_even_with_an_old_token(): void
    {
        $user = $this->makeUser();
        $token = $this->login($user);
        $this->asToken($token)->getJson('/api/me')->assertOk();

        // تعطيل مباشر من برا الـ controller (مثلاً من tinker أو SQL)
        $user->forceFill(['is_active' => false])->save();

        $this->asToken($token)->getJson('/api/me')->assertStatus(403);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_admin_deactivating_or_changing_role_revokes_tokens(): void
    {
        $admin = $this->actingAsRole('admin');
        $user = $this->makeUser();
        $user->createToken('a');
        $user->createToken('b');

        $this->putJson("/api/users/{$user->id}", ['is_active' => false])->assertOk();
        $this->assertSame(0, $user->tokens()->count());

        $user->createToken('c');
        $this->putJson("/api/users/{$user->id}", ['role' => 'waiter'])->assertOk();
        $this->assertSame(0, $user->tokens()->count());

        // تعديل عادي (اسم) ما بيسحب التوكنات
        $user->createToken('d');
        $this->putJson("/api/users/{$user->id}", ['name' => 'New Name'])->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_deleting_a_user_removes_his_tokens(): void
    {
        $this->actingAsRole('admin');
        $user = $this->makeUser();
        $user->createToken('a');

        $this->deleteJson("/api/users/{$user->id}")->assertOk();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_admin_cannot_deactivate_or_demote_himself(): void
    {
        $admin = $this->actingAsRole('admin');

        $this->putJson("/api/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
        $this->putJson("/api/users/{$admin->id}", ['role' => 'cashier'])->assertStatus(422);
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertTrue((bool) $admin->fresh()->is_active);
    }

    public function test_change_password_revokes_other_sessions_but_keeps_current(): void
    {
        $user = $this->makeUser();
        $current = $this->login($user);
        $other = $this->login($user);

        $this->asToken($current)->postJson('/api/change-password', [
            'current_password' => 'secret123',
            'password' => 'newsecret456',
            'password_confirmation' => 'newsecret456',
        ])->assertOk();

        $this->asToken($current)->getJson('/api/me')->assertOk();
        $this->asToken($other)->getJson('/api/me')->assertStatus(401);
    }

    public function test_tokens_expire(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = $this->makeUser();
        $token = $this->login($user);
        $this->asToken($token)->getJson('/api/me')->assertOk();

        PersonalAccessToken::query()->update(['created_at' => now()->subMinutes(61)]);

        $this->asToken($token)->getJson('/api/me')->assertStatus(401);
    }

    public function test_default_token_lifetime_is_not_unlimited(): void
    {
        $this->assertGreaterThan(0, config('sanctum.expiration'));
    }

    // ---------------- باقي التقوية ----------------

    public function test_forgot_password_does_not_reveal_whether_email_exists(): void
    {
        $user = $this->makeUser();

        $known = $this->postJson('/api/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@nowhere.test']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    public function test_unauthenticated_api_call_without_accept_header_returns_json_401(): void
    {
        // قبل: كان يحاول redirect لـ route('login') الغير موجود => 500
        $this->get('/api/orders')->assertStatus(401)->assertJsonStructure(['message']);
    }

    public function test_cors_blocks_unknown_origins_and_allows_configured_ones(): void
    {
        $this->call('OPTIONS', '/api/orders', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('OPTIONS', '/api/orders', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
