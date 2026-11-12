<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Api\V1\TwoFactorController;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccountDeleted;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\DailyDigest;
use App\Notifications\EmailAddressChanged;
use App\Notifications\ResetPasswordLink;
use App\Notifications\SecurityNotice;
use App\Notifications\VerifyEmailAddress;
use App\Services\Auth\TwoFactorService;
use App\Services\Billing\BillingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeBillingGateway;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Turns the frontend link from an e-mail into the signed API path the frontend calls.
     */
    protected function apiPathFromLink(string $link, string $prefix): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $id = $query['id'];
        $hash = $query['hash'];
        unset($query['id'], $query['hash']);

        return "/api/v1/{$prefix}/{$id}/{$hash}?".http_build_query($query);
    }

    public function test_registration_requires_email_verification_before_using_the_app(): void
    {
        Notification::fake();

        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'Új Ügyfél', 'email' => 'uj@example.com', 'password' => 'jelszo1234', 'password_confirmation' => 'jelszo1234',
            'workspace_name' => 'Új Kft.', 'accept_terms' => true,
        ])->assertCreated()->assertJsonPath('user.email_verified_at', null)->json('token');
        $user = User::where('email', 'uj@example.com')->sole();
        $workspaceId = $user->current_workspace_id;

        $this->withToken($token)->withHeader('X-Workspace-Id', (string) $workspaceId)->getJson('/api/v1/clients')
            ->assertForbidden()->assertJsonPath('code', 'email_unverified');

        $link = null;
        Notification::assertSentTo($user, VerifyEmailAddress::class, function (VerifyEmailAddress $notification) use (&$link) {
            $link = $notification->url;

            return str_starts_with($link, config('app.frontend_url').'/verify-email/confirm?');
        });

        /* Tampered signature is rejected, the real link works without being logged in. */
        $this->getJson(str_replace('signature=', 'signature=x', $this->apiPathFromLink($link, 'auth/email/verify')))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $response = $this->getJson($this->apiPathFromLink($link, 'auth/email/verify'));
        $response->assertOk()->assertJsonPath('verified', true);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeader('X-Workspace-Id', (string) $workspaceId)->getJson('/api/v1/clients')->assertOk();
    }

    public function test_verification_link_for_another_email_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $path = $this->apiPathFromLink($user->frontendSignedUrl('/x', 'api.v1.auth.verify', ['id' => $other->id, 'hash' => sha1($user->email)]), 'auth/email/verify');

        $this->getJson($path)->assertForbidden();
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_forgot_and_reset_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'anna@example.com']);
        $user->createToken('old-device');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nincs@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'anna@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use (&$token) {
            parse_str((string) parse_url($notification->url, PHP_URL_QUERY), $query);
            $token = $query['token'];

            return str_contains($notification->url, '/reset-password?');
        });

        $this->postJson('/api/v1/auth/reset-password', ['token' => 'wrong', 'email' => 'anna@example.com', 'password' => 'ujjelszo123', 'password_confirmation' => 'ujjelszo123'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'anna@example.com', 'password' => 'ujjelszo123', 'password_confirmation' => 'ujjelszo123'])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count(), 'all sessions are signed out after a reset');
        $this->postJson('/api/v1/auth/login', ['email' => 'anna@example.com', 'password' => 'ujjelszo123'])->assertOk()->assertJsonStructure(['token']);
        Notification::assertSentTo($user, SecurityNotice::class, fn (SecurityNotice $n) => $n->event === 'password_changed');
    }

    public function test_email_change_needs_password_and_confirmation_of_the_new_address(): void
    {
        Notification::fake();
        $this->signIn();
        $oldEmail = $this->user->email;

        $this->patchJson('/api/v1/auth/profile', ['email' => 'hack@example.com'])->assertOk();
        $this->assertSame($oldEmail, $this->user->fresh()->email, 'profile update cannot change the e-mail');

        $this->postJson('/api/v1/auth/email-change', ['email' => 'uj@example.com', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/email-change', ['email' => 'uj@example.com', 'password' => 'password'])->assertOk()->assertJsonPath('user.pending_email', 'uj@example.com');
        $this->assertSame($oldEmail, $this->user->fresh()->email);

        $link = null;
        Notification::assertSentTo(new AnonymousNotifiable, ConfirmEmailChange::class, function (ConfirmEmailChange $notification, $channels, $notifiable) use (&$link) {
            $link = $notification->url;

            return $notifiable->routes['mail'] === 'uj@example.com';
        });

        $this->getJson($this->apiPathFromLink($link, 'auth/email-change/confirm'))->assertOk();
        $this->assertSame('uj@example.com', $this->user->fresh()->email);
        $this->assertNull($this->user->fresh()->pending_email);
        Notification::assertSentTo(new AnonymousNotifiable, EmailAddressChanged::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === $oldEmail);
    }

    public function test_two_factor_setup_login_challenge_recovery_and_disable(): void
    {
        Notification::fake();
        $this->signIn();
        $twoFactor = app(TwoFactorService::class);

        $setup = $this->postJson('/api/v1/auth/two-factor/setup', ['password' => 'password'])->assertOk()->assertJsonStructure(['secret', 'otpauth_url', 'qr_svg'])->json();
        $secret = str_replace(' ', '', $setup['secret']);
        $this->assertStringContainsString('<svg', $setup['qr_svg']);

        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();
        $recovery = $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => $twoFactor->codeAt($secret, intdiv(time(), 30))])
            ->assertOk()->assertJsonPath('user.two_factor_enabled', true)->json('recovery_codes');
        $this->assertCount(8, $recovery);
        Notification::assertSentTo($this->user, SecurityNotice::class, fn ($n) => $n->event === 'two_factor_enabled');

        /* Password alone no longer returns a token. */
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token')->json();

        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $login['challenge'], 'code' => '123456'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => 'forged', 'code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('challenge');

        /* The code of the step used at confirmation cannot be replayed; the next step works. */
        $nextStep = intdiv(time(), 30) + 1;
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $login['challenge'], 'code' => $twoFactor->codeAt($secret, $nextStep)])
            ->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $login['challenge'], 'code' => $twoFactor->codeAt($secret, $nextStep)])
            ->assertUnprocessable();

        /* A recovery code works exactly once. */
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $login['challenge'], 'recovery_code' => $recovery[0]])->assertOk();
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $login['challenge'], 'recovery_code' => $recovery[0]])->assertUnprocessable();
        $this->assertSame(7, $twoFactor->remainingRecoveryCodes($this->user->fresh()));

        Sanctum::actingAs($this->user->fresh());
        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'wrong'])->assertUnprocessable();
        $this->deleteJson('/api/v1/auth/two-factor', ['password' => 'password'])->assertOk()->assertJsonPath('user.two_factor_enabled', false);
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $this->signIn();
        $this->user->forceFill(['two_factor_secret' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'), 'two_factor_confirmed_at' => now()])->save();
        $challenge = TwoFactorController::makeChallenge($this->user, 'web');

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge' => $challenge, 'code' => app(TwoFactorService::class)->codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(time(), 30))])
            ->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }

    public function test_gdpr_export_contains_personal_and_workspace_data(): void
    {
        $this->signIn();
        Client::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Export Elemér']);

        $response = $this->get('/api/v1/auth/account/export')->assertOk();
        $data = json_decode($response->streamedContent(), true);

        $this->assertSame($this->user->email, $data['user']['email']);
        $this->assertSame('Export Elemér', $data['owned_workspaces'][0]['clients'][0]['name']);
    }

    public function test_account_deletion_removes_data_but_keeps_invoices(): void
    {
        Notification::fake();
        $gateway = new FakeBillingGateway;
        $this->app->instance(BillingGateway::class, $gateway);
        $this->signIn();
        $email = $this->user->email;
        Client::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->workspace->forceFill(['stripe_id' => 'cus_x'])->save();
        $this->workspace->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_x', 'stripe_status' => 'active', 'stripe_price' => 'p', 'quantity' => 1]);
        $invoice = Invoice::create([
            'workspace_id' => $this->workspace->id, 'stripe_invoice_id' => 'in_keep', 'currency' => 'HUF', 'net_amount' => 2354, 'vat_amount' => 636,
            'gross_amount' => 2990, 'vat_key' => '27', 'buyer' => ['name' => 'Teszt Kft.'], 'number' => 'UFL-1', 'status' => 'issued',
        ]);

        $this->deleteJson('/api/v1/auth/account', ['password' => 'password', 'confirmation' => 'rossz'])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->withHeader('X-Locale', 'hu')->deleteJson('/api/v1/auth/account', ['password' => 'password', 'confirmation' => 'törlés'])->assertOk();

        $this->assertNull(User::where('email', $email)->first());
        $this->assertNull(Workspace::find($this->workspace->id));
        $this->assertSame(0, Client::withoutGlobalScopes()->count());
        $this->assertNotNull($invoice->fresh(), 'invoices are retained for the legal period');
        $this->assertNull($invoice->fresh()->workspace_id);
        $this->assertContains('cancelNow', array_column($gateway->calls, 'method'));
        Notification::assertSentTo(new AnonymousNotifiable, AccountDeleted::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === $email);
    }

    public function test_owner_with_team_must_transfer_ownership_before_deletion(): void
    {
        $this->signIn('business');
        $colleague = $this->addMember(WorkspaceRole::Admin);

        $this->deleteJson('/api/v1/auth/account', ['password' => 'password', 'confirmation' => 'TÖRLÉS'])
            ->assertUnprocessable()->assertJsonValidationErrors('account');

        $this->postJson('/api/v1/team/owner', ['user_id' => $colleague->id])->assertOk();
        $this->assertSame($colleague->id, $this->workspace->fresh()->owner_id);

        $this->deleteJson('/api/v1/auth/account', ['password' => 'password', 'confirmation' => 'TÖRLÉS'])->assertOk();
        $this->assertNotNull(Workspace::find($this->workspace->id), 'the workspace stays with the new owner');
        $this->assertSame('owner', $colleague->roleIn($this->workspace)?->value);
    }

    public function test_daily_digest_lists_todays_and_overdue_tasks_once_per_day(): void
    {
        Notification::fake();
        $this->signIn('pro');
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->user->id, 'title' => 'Mai hívás', 'due_at' => now()->endOfDay()->subHour()]);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->user->id, 'title' => 'Lejárt', 'due_at' => now()->subDays(2)]);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->user->id, 'title' => 'Jövő hét', 'due_at' => now()->addWeek()]);

        $this->artisan('crm:send-daily-digest --force')->assertSuccessful();
        $this->artisan('crm:send-daily-digest --force')->assertSuccessful();

        Notification::assertSentToTimes($this->user, DailyDigest::class, 1);
        Notification::assertSentTo($this->user, DailyDigest::class, function (DailyDigest $digest) {
            $titles = array_column($digest->groups[0]['tasks'], 'title');

            return $titles === ['Lejárt', 'Mai hívás'];
        });
    }

    public function test_notification_preferences_are_respected(): void
    {
        Notification::fake();
        $this->signIn('pro');
        $this->patchJson('/api/v1/auth/profile', ['notify_daily_digest' => false, 'notify_task_reminders' => false])->assertOk()->assertJsonPath('user.notify_daily_digest', false);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->user->id, 'due_at' => now()->addMinutes(20)]);

        $this->artisan('crm:send-daily-digest --force')->assertSuccessful();
        $this->artisan('crm:send-task-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($this->user);
    }

    public function test_branded_email_renders_in_the_users_language(): void
    {
        $user = User::factory()->create(['name' => 'Kiss Anna', 'locale' => 'hu']);
        $this->app->setLocale($user->preferredLocale());
        $html = (string) (new VerifyEmailAddress('https://app.example/verify'))->toMail($user)->render();

        $this->assertStringContainsString('Szia, Kiss Anna!', $html);
        $this->assertStringContainsString('E-mail-cím megerősítése', $html);
        $this->assertStringContainsString('Vállalkozó', $html);
        $this->assertStringContainsString('#0f6b5c', $html);
        $this->assertStringContainsString('a VállalkozóOS csapata', $html);
        $this->assertStringNotContainsString('Laravel', $html);
    }
}
