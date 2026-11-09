<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthAndOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_default_categories_and_token(): void
    {
        $res = $this->postJson('/api/register', [
            'name' => 'Ivan', 'email' => 'ivan@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->assertJsonStructure(['user' => ['id', 'email'], 'token']);

        $user = User::find($res->json('user.id'));
        $this->assertGreaterThan(20, $user->categories()->count());
        $this->assertGreaterThan(5, $user->rules()->count());
    }

    public function test_user_can_delete_account_with_password(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        Account::create(['user_id' => $user->id, 'name' => 'A', 'type' => 'checking']);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/me', ['password' => 'wrong'])->assertStatus(422);
        $this->deleteJson('/api/me', ['password' => 'secret123'])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('accounts', ['user_id' => $user->id]);
    }

    public function test_login_rejects_bad_password(): void
    {
        User::factory()->create(['email' => 'a@b.c']);
        $this->postJson('/api/login', ['email' => 'a@b.c', 'password' => 'wrong'])->assertStatus(422);
    }

    public function test_protected_routes_require_token(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_user_cannot_access_another_users_account(): void
    {
        $owner = User::factory()->create();
        $account = Account::create(['user_id' => $owner->id, 'name' => 'Secret', 'type' => 'checking']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/accounts/{$account->id}")->assertNotFound();
        $this->deleteJson("/api/accounts/{$account->id}")->assertNotFound();
        $this->postJson('/api/transactions', [
            'account_id' => $account->id, 'type' => 'expense', 'amount' => 10, 'date' => '2026-01-01',
        ])->assertStatus(422);
    }
}
