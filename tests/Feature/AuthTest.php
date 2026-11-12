<?php

namespace Tests\Feature;

use App\Models\Pipeline;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_workspace_with_pro_trial_and_default_pipeline(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Kovács Anna',
            'email' => 'Anna@Example.com',
            'password' => 'titkos-jelszo-1',
            'password_confirmation' => 'titkos-jelszo-1',
            'workspace_name' => 'Anna Szépségszalon',
            'locale' => 'hu',
            'accept_terms' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'email'], 'workspaces'])
            ->assertJsonPath('user.email', 'anna@example.com')
            ->assertJsonPath('workspaces.0.role', 'owner')
            ->assertJsonPath('workspaces.0.effective_plan', 'pro')
            ->assertJsonPath('workspaces.0.on_trial', true);

        $workspace = Workspace::firstOrFail();
        $this->assertTrue($workspace->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));

        $pipeline = Pipeline::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame(
            ['Új érdeklődő', 'Kapcsolatfelvétel', 'Ajánlat elküldve', 'Tárgyalás', 'Megnyert', 'Elvesztett'],
            $pipeline->stages()->withoutGlobalScopes()->pluck('name')->all(),
        );
    }

    public function test_registration_requires_terms_acceptance(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'password1', 'password_confirmation' => 'password1', 'workspace_name' => 'X',
        ])->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
    }

    public function test_login_returns_token_and_rejects_wrong_password(): void
    {
        $user = User::factory()->create(['email' => 'peter@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'peter@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'peter@example.com', 'password' => 'password'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_validation_messages_follow_requested_locale(): void
    {
        $this->withHeader('X-Locale', 'hu')->postJson('/api/v1/auth/login', [])
            ->assertJsonPath('errors.email.0', 'A(z) e-mail-cím mező kitöltése kötelező.');

        $this->withHeader('X-Locale', 'de')->postJson('/api/v1/auth/login', [])
            ->assertJsonPath('errors.email.0', 'E-Mail-Adresse muss ausgefüllt werden.');
    }

    public function test_profile_update_changes_language_and_theme(): void
    {
        $this->signIn();

        $this->patchJson('/api/v1/auth/profile', ['locale' => 'de', 'theme' => 'dark'])
            ->assertOk()->assertJsonPath('user.locale', 'de')->assertJsonPath('user.theme', 'dark');
    }
}
