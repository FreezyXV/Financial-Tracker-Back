<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $profile = []): array
    {
        return [
            'profile' => array_merge([
                'birth_year' => 1994, 'country' => 'FR', 'household' => 'family', 'children' => 2,
                'employment' => 'freelance', 'housing' => 'owner_mortgage', 'monthly_income' => 4200,
                'income_variable' => true, 'payday' => 5, 'housing_cost' => 1100, 'other_fixed_costs' => 600,
                'variable_spending' => 1200, 'goals' => ['emergency', 'project', 'retirement'],
                'project_name' => 'Voiture', 'project_amount' => 8000, 'project_date' => now()->addYear()->format('Y-m-d'),
                'experience' => 'beginner', 'risk' => 'balanced', 'horizon' => 'long', 'monthly_invest' => 300,
            ], $profile),
            'accounts' => [
                ['type' => 'checking', 'institution' => 'BoursoBank', 'balance' => 1500],
                ['type' => 'livret_a', 'balance' => 2000],
                ['type' => 'pea', 'balance' => 0, 'opened_year' => 2021],
                ['type' => 'loan', 'name' => 'Prêt auto', 'balance' => 7000, 'interest_rate' => 5.5],
            ],
            'options' => ['create_budgets' => true, 'create_recurring' => true, 'create_goals' => true],
        ];
    }

    public function test_onboarding_builds_a_personal_workspace(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/api/onboarding', $this->payload())->assertOk();
        $user->refresh();

        $this->assertNotNull($user->onboarded_at);
        $this->assertSame(4, $res->json('created.accounts'));
        $this->assertTrue($user->categories()->where('name', 'Cotisations sociales (URSSAF)')->exists());
        $this->assertTrue($user->categories()->where('name', 'Enfants')->exists());
        $this->assertTrue($user->categories()->where('name', 'Crédit immobilier')->exists());
        // Freelance avec enfants et crédit : 6 + 1 mois de coussin
        $this->assertTrue($user->goals()->where('name', 'Épargne de précaution (7 mois)')->where('target_amount', 20300)->exists());
        $this->assertTrue($user->goals()->where('name', 'Voiture')->exists());
        $this->assertSame(-7000.0, $user->accounts()->where('type', 'loan')->first()->opening_balance);
        $this->assertSame('2021-01-01', $user->accounts()->where('type', 'pea')->first()->opened_at->format('Y-m-d'));
        // Revenu variable : pas de salaire programmé, mais crédit et investissement oui
        $this->assertFalse($user->recurring()->where('type', 'income')->exists());
        $this->assertTrue($user->recurring()->where('description', 'Échéance crédit immobilier')->exists());
        $this->assertTrue($user->recurring()->where('type', 'transfer')->where('amount', 300)->exists());
        $this->assertGreaterThan(5, $user->budgets()->count());
        $this->assertEquals(630, $user->large_transaction_threshold);

        $ids = array_column($res->json('recommendations'), 'id');
        $this->assertContains('emergency', $ids);
        $this->assertContains('debt-'.$user->accounts()->where('type', 'loan')->value('id'), $ids);

        // Idempotent
        $again = $this->postJson('/api/onboarding', $this->payload())->assertOk();
        $this->assertSame(0, $again->json('created.accounts'));
        $this->assertSame(0, $again->json('created.budgets'));
        $this->assertSame(1, $user->goals()->where('name', 'Voiture')->count());
    }

    public function test_preview_estimates_tax_bracket_and_cushion(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/onboarding/preview', ['profile' => ['household' => 'single', 'monthly_income' => 3500, 'employment' => 'cdi', 'housing_cost' => 900, 'variable_spending' => 1100]])
            ->assertOk()->assertJson(['emergency_months' => 3, 'emergency_target' => 6000, 'tax_parts' => 1, 'tmi' => 30]);
    }

    public function test_tax_parts(): void
    {
        $this->assertSame(3.0, OnboardingService::taxParts(['household' => 'couple', 'children' => 2]));
        $this->assertSame(4.0, OnboardingService::taxParts(['household' => 'family', 'children' => 3]));
        $this->assertSame(1.5, OnboardingService::taxParts(['household' => 'single', 'children' => 1]));
    }

    public function test_user_without_profile_is_invited_to_onboard(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/recommendations')->assertOk()->assertJsonPath('0.id', 'onboarding');
        $this->postJson('/api/onboarding/skip')->assertOk()->assertJsonPath('profile.skipped', true);
    }

    public function test_invalid_answers_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/onboarding', ['profile' => ['employment' => 'astronaut']])->assertStatus(422);
    }
}
