<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\Quote;
use App\Models\Task;
use App\Notifications\QuoteFollowUpDue;
use App\Notifications\QuoteSent;
use App\Notifications\QuoteStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function createQuote(array $overrides = []): array
    {
        return $this->postJson('/api/v1/quotes', [
            'title' => 'Klíma telepítés',
            'items' => [
                ['description' => 'Split klíma 3,5 kW', 'quantity' => 2, 'unit' => 'db', 'unit_price' => 250000, 'vat_rate' => 27],
                ['description' => 'Szerelés', 'quantity' => 1, 'unit' => 'alkalom', 'unit_price' => 60000, 'vat_rate' => 27],
            ],
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    protected function sendQuote(int $id): array
    {
        return $this->postJson("/api/v1/quotes/{$id}/send", ['email' => 'vevo@example.com', 'name' => 'Kiss Anna', 'message' => 'Köszönöm a mai egyeztetést!'])->assertOk()->json('data');
    }

    protected function token(int $id): string
    {
        return Quote::findOrFail($id)->public_token;
    }

    public function test_quote_is_created_with_numbered_totals_and_recipient_from_client(): void
    {
        $this->signIn('pro');
        $client = Client::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Kiss Anna', 'email' => 'anna@example.com']);

        $quote = $this->createQuote(['client_id' => $client->id]);

        $this->assertSame('AJ-'.now()->year.'-0001', $quote['number']);
        $this->assertSame('draft', $quote['status']);
        $this->assertEquals(560000, $quote['net_total']);
        $this->assertEquals(151200, $quote['vat_total']);
        $this->assertEquals(711200, $quote['gross_total']);
        $this->assertSame('anna@example.com', $quote['recipient_email']);
        $this->assertSame('Kiss Anna', $quote['recipient_name']);
        $this->assertCount(2, $quote['items']);
        $this->assertSame('AJ-'.now()->year.'-0002', $this->createQuote()['number']);
    }

    public function test_only_drafts_can_be_edited_but_follow_up_date_can_always_be_set(): void
    {
        $this->signIn('pro');
        $quote = $this->createQuote();

        $this->patchJson("/api/v1/quotes/{$quote['id']}", ['items' => [['description' => 'Csak szerelés', 'unit_price' => 10000, 'vat_rate' => 0]]])
            ->assertOk()->assertJsonPath('data.gross_total', 10000);

        $this->sendQuote($quote['id']);

        $this->patchJson("/api/v1/quotes/{$quote['id']}", ['title' => 'Átírva'])->assertStatus(422);
        $this->patchJson("/api/v1/quotes/{$quote['id']}", ['follow_up_at' => now()->addDay()->toIso8601String()])->assertOk();
        $this->assertSame('Klíma telepítés', Quote::find($quote['id'])->title);
    }

    public function test_sending_emails_the_customer_and_schedules_a_follow_up(): void
    {
        $this->signIn('pro');
        $quote = $this->createQuote();

        $sent = $this->sendQuote($quote['id']);

        $this->assertSame('sent', $sent['status']);
        $this->assertStringContainsString('/q/', $sent['public_url']);
        $this->assertNotNull($sent['follow_up_at']);
        $this->assertSame(['sent', 'created'], array_column($sent['events'], 'type'));

        Notification::assertSentOnDemand(QuoteSent::class, function (QuoteSent $notification, array $channels, AnonymousNotifiable $notifiable) {
            $mail = $notification->toMail($notifiable)->render();

            return array_key_first($notifiable->routes['mail']) === 'vevo@example.com'
                && str_contains($mail, $notification->quote->publicUrl())
                && str_contains($mail, 'Köszönöm a mai egyeztetést!');
        });
    }

    public function test_a_quote_without_items_cannot_be_sent(): void
    {
        $this->signIn('pro');
        $quote = $this->createQuote(['items' => []]);

        $this->postJson("/api/v1/quotes/{$quote['id']}/send", ['email' => 'vevo@example.com'])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_customer_view_is_tracked_and_owner_notified_once(): void
    {
        $this->signIn('pro');
        $quote = $this->createQuote();
        $this->sendQuote($quote['id']);
        $token = $this->token($quote['id']);

        $page = $this->getJson("/api/v1/public/quotes/{$token}")->assertOk();
        $this->getJson("/api/v1/public/quotes/{$token}")->assertOk();

        $page->assertJsonPath('data.status', 'viewed')->assertJsonPath('data.company.name', 'Teszt Kft.')->assertJsonCount(2, 'data.items');
        $page->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.workspace_id')->assertJsonMissingPath('data.public_token');

        $stored = Quote::find($quote['id']);
        $this->assertSame(2, $stored->view_count);
        $this->assertNotNull($stored->first_viewed_at);
        Notification::assertSentToTimes($this->user, QuoteStatusChanged::class, 1);

        $this->getJson("/api/v1/public/quotes/{$token}?preview=1")->assertOk();
        $this->assertSame(2, $stored->fresh()->view_count);
    }

    public function test_customer_acceptance_wins_the_linked_deal_and_notifies_the_owner(): void
    {
        $this->signIn('pro');
        $pipeline = Pipeline::firstOrFail();
        $openStage = $pipeline->stages()->where('type', 'open')->firstOrFail();
        $client = Client::factory()->create(['workspace_id' => $this->workspace->id]);
        $deal = Deal::factory()->create(['workspace_id' => $this->workspace->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $openStage->id, 'value' => 0]);
        $quote = $this->createQuote(['deal_id' => $deal->id, 'client_id' => $client->id]);
        $this->sendQuote($quote['id']);
        $token = $this->token($quote['id']);

        $this->postJson("/api/v1/public/quotes/{$token}/accept", ['name' => 'Kiss Anna'])->assertStatus(422)->assertJsonValidationErrors('agree');
        $this->postJson("/api/v1/public/quotes/{$token}/accept", ['name' => 'Kiss Anna', 'agree' => true])
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.accepted_by_name', 'Kiss Anna');

        $deal->refresh();
        $this->assertSame('won', $deal->stage->type);
        $this->assertNotNull($deal->closed_at);
        $this->assertEquals(560000, $deal->value);
        $this->assertNull(Quote::find($quote['id'])->follow_up_at);
        $this->assertDatabaseHas('activities', ['subject_type' => 'client', 'subject_id' => $client->id, 'type' => 'quote_accepted']);
        Notification::assertSentTo($this->user, QuoteStatusChanged::class, fn ($n) => $n->event === 'accepted');

        $this->postJson("/api/v1/public/quotes/{$token}/decline", ['reason' => 'mégsem'])->assertStatus(422);
        $this->assertSame('accepted', Quote::find($quote['id'])->status);
    }

    public function test_customer_can_decline_with_a_reason(): void
    {
        $this->signIn('pro');
        $quote = $this->createQuote();
        $this->sendQuote($quote['id']);

        $this->postJson('/api/v1/public/quotes/'.$this->token($quote['id']).'/decline', ['reason' => 'Túl drága'])
            ->assertOk()->assertJsonPath('data.status', 'declined');

        $this->assertSame('Túl drága', Quote::find($quote['id'])->decline_reason);
        Notification::assertSentTo($this->user, QuoteStatusChanged::class, fn ($n) => $n->event === 'declined');
    }

    public function test_drafts_unknown_and_malformed_tokens_are_not_reachable(): void
    {
        $this->signIn('pro');
        $quote = Quote::factory()->create(['workspace_id' => $this->workspace->id]);
        $quote->forceFill(['public_token' => str_repeat('a', 48)])->save();

        $this->getJson('/api/v1/public/quotes/'.str_repeat('a', 48))->assertNotFound();
        $this->getJson('/api/v1/public/quotes/'.str_repeat('b', 48))->assertNotFound();
        $this->getJson('/api/v1/public/quotes/short')->assertNotFound();
        $this->postJson('/api/v1/public/quotes/'.str_repeat('a', 48).'/accept', ['name' => 'X', 'agree' => true])->assertNotFound();
        $this->assertSame('draft', $quote->fresh()->status);
    }

    public function test_expired_quotes_cannot_be_accepted_and_are_closed_by_the_scheduler(): void
    {
        $this->signIn('pro');
        $quote = $this->createQuote(['valid_until' => now()->addDays(2)->toDateString()]);
        $this->sendQuote($quote['id']);
        $token = $this->token($quote['id']);

        $this->travel(4)->days();

        $this->getJson("/api/v1/public/quotes/{$token}")->assertOk()->assertJsonPath('data.is_expired', true);
        $this->postJson("/api/v1/public/quotes/{$token}/accept", ['name' => 'Kiss Anna', 'agree' => true])->assertStatus(422);

        $this->artisan('crm:quote-follow-ups')->assertSuccessful();
        $this->assertSame('expired', Quote::find($quote['id'])->status);
    }

    public function test_follow_up_reminders_create_tasks_and_stop_after_the_maximum(): void
    {
        $this->signIn('pro');
        $quote = $this->createQuote(['valid_until' => now()->addMonths(2)->toDateString()]);
        $this->sendQuote($quote['id']);

        $this->artisan('crm:quote-follow-ups')->assertSuccessful();
        $this->assertSame(0, Task::count());

        for ($round = 1; $round <= 4; $round++) {
            $this->travel(3)->days();
            $this->artisan('crm:quote-follow-ups')->assertSuccessful();
        }

        $stored = Quote::find($quote['id']);
        $this->assertSame(Quote::MAX_FOLLOW_UPS, $stored->follow_up_count);
        $this->assertNull($stored->follow_up_at);
        $this->assertSame(Quote::MAX_FOLLOW_UPS, Task::where('assignee_id', $this->user->id)->where('priority', 'high')->count());
        Notification::assertSentToTimes($this->user, QuoteFollowUpDue::class, Quote::MAX_FOLLOW_UPS);
    }

    public function test_free_plan_has_no_automatic_follow_ups_and_a_monthly_quote_limit(): void
    {
        $this->signIn('free');
        $quote = $this->createQuote();
        $sent = $this->sendQuote($quote['id']);
        $this->assertNull($sent['follow_up_at']);

        Quote::factory()->count(4)->create(['workspace_id' => $this->workspace->id]);
        $this->postJson('/api/v1/quotes', ['title' => 'Hatodik'])->assertStatus(402);
    }

    public function test_staff_can_record_an_offline_answer_and_duplicate_a_quote(): void
    {
        $this->signIn('pro');
        $quote = $this->createQuote();
        $this->sendQuote($quote['id']);

        $this->postJson("/api/v1/quotes/{$quote['id']}/mark", ['status' => 'accepted', 'name' => 'Telefonon: Kiss Anna'])
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.events.0.type', 'marked_accepted');
        Notification::assertNotSentTo($this->user, QuoteStatusChanged::class);

        $copy = $this->postJson("/api/v1/quotes/{$quote['id']}/duplicate")->assertCreated()->json('data');
        $this->assertSame('draft', $copy['status']);
        $this->assertCount(2, $copy['items']);
        $this->assertNotSame($quote['number'], $copy['number']);

        $this->getJson('/api/v1/quotes?status=accepted')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('stats.counts.accepted', 1)->assertJsonPath('stats.counts.draft', 1)->assertJsonPath('stats.acceptance_rate', 100);
    }
}
