<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Notifications\NewLeadReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PublicFormTest extends TestCase
{
    use RefreshDatabase;

    protected function makeForm(array $attributes = []): Form
    {
        return Form::factory()->create(['workspace_id' => $this->workspace->id] + $attributes);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(array $data = [], int $secondsAgo = 10): array
    {
        return [
            'data' => $data + ['name' => 'Tóth Gábor', 'email' => 'gabor@example.com', 'phone' => '+36 70 555 1234', 'message' => 'Klímát szeretnék'],
            '_token' => Crypt::encryptString((string) (now()->getTimestamp() - $secondsAgo)),
            'utm' => ['source' => 'facebook', 'medium' => 'social', 'campaign' => 'tavasz'],
            'referrer' => 'https://facebook.com/',
        ];
    }

    public function test_public_form_can_be_loaded_without_authentication(): void
    {
        $this->signIn();
        $form = $this->makeForm();
        $this->app['auth']->forgetGuards();

        $this->withoutHeader('X-Workspace-Id')->getJson("/api/v1/public/forms/{$form->slug}")
            ->assertOk()->assertJsonStructure(['data' => ['fields', 'title'], 'token', 'honeypot']);
    }

    public function test_submission_creates_lead_with_source_tracking_and_notifies(): void
    {
        Notification::fake();
        $this->signIn('pro');
        $form = $this->makeForm();

        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload())->assertCreated();

        $lead = Lead::firstOrFail();
        $this->assertSame('form', $lead->source);
        $this->assertSame('facebook', $lead->utm_source);
        $this->assertSame('tavasz', $lead->utm_campaign);
        $this->assertSame($form->id, $lead->form_id);
        $this->assertSame(1, $form->fresh()->submissions_count);
        Notification::assertSentTo($this->user, NewLeadReceived::class);

        /* A second submission from the same e-mail is attached to the existing lead. */
        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload(['message' => 'Mikor jönnek?']))->assertCreated();
        $this->assertSame(1, Lead::count());
        $this->assertSame(2, FormSubmission::where('lead_id', $lead->id)->count());
    }

    public function test_utm_is_not_stored_on_free_plan(): void
    {
        $this->signIn('free');
        $form = $this->makeForm();

        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload())->assertCreated();

        $this->assertNull(Lead::firstOrFail()->utm_source);
    }

    public function test_honeypot_and_too_fast_submissions_are_marked_as_spam(): void
    {
        $this->signIn();
        $form = $this->makeForm();

        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload() + ['website' => 'http://spam.example'])->assertCreated();
        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload([], 0))->assertCreated();
        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", ['data' => ['email' => 'a@b.hu'], '_token' => 'invalid'])->assertCreated();

        $this->assertSame(0, Lead::count());
        $this->assertSame(3, FormSubmission::where('status', 'spam')->count());
    }

    public function test_server_side_validation_privacy_and_marketing_consent(): void
    {
        $this->signIn();
        $form = $this->makeForm(['privacy_url' => 'https://example.com/adatvedelem', 'privacy_version' => '2026-01', 'ask_marketing_consent' => true]);

        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload(['email' => 'nem-email']))
            ->assertUnprocessable()->assertJsonValidationErrors(['data.email', 'privacy_accepted']);

        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload() + ['privacy_accepted' => true])->assertCreated();

        $submission = FormSubmission::firstOrFail();
        $this->assertTrue($submission->privacy_accepted);
        $this->assertFalse($submission->marketing_consent);
        $this->assertSame('2026-01', $submission->privacy_version);
    }

    public function test_draft_forms_and_foreign_origins_are_rejected(): void
    {
        $this->signIn();
        $draft = $this->makeForm(['status' => 'draft']);
        $form = $this->makeForm();

        $this->getJson("/api/v1/public/forms/{$draft->slug}")->assertNotFound();
        $this->withHeader('Origin', 'https://evil.example')->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload())->assertForbidden();
    }

    public function test_monthly_submission_limit(): void
    {
        $this->signIn('free');
        $form = $this->makeForm();
        FormSubmission::query()->insert(array_fill(0, 50, [
            'workspace_id' => $this->workspace->id, 'form_id' => $form->id, 'data' => '{}', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload())->assertStatus(429);
    }

    public function test_submissions_are_rate_limited(): void
    {
        $this->signIn('business');
        $form = $this->makeForm();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload(['email' => "user{$i}@example.com"]))->assertCreated();
        }

        $this->postJson("/api/v1/public/forms/{$form->slug}/submissions", $this->payload())->assertTooManyRequests();
    }
}
