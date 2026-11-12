<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AutomationRule;
use App\Models\Client;
use App\Models\CustomFieldDefinition;
use App\Models\Deal;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Import;
use App\Models\Invitation;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\Quote;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingGateway;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fakes\FakeBillingGateway;
use Tests\TestCase;

/**
 * Multi-tenant isolation: a user of workspace A must never read, change, delete or reference data of workspace B,
 * through any workspace scoped endpoint. New endpoints are picked up automatically from the route list.
 */
class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'VICTIM-SECRET';

    protected User $victim;

    protected Workspace $victimWorkspace;

    /** @var array<string, int> route parameter name => id of a victim record */
    protected array $victimIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        Storage::fake('local');
        $this->app->instance(BillingGateway::class, new FakeBillingGateway);

        /* Attacker: workspace A on Business (every feature unlocked, so plan limits never mask a leak). */
        $this->signIn('business');

        /* Victim: workspace B with one record of every kind. */
        $this->victim = User::factory()->create(['name' => self::MARKER.' Owner']);
        $this->victimWorkspace = app(WorkspaceService::class)->create($this->victim, ['name' => self::MARKER.' Kft.']);
        $this->victimWorkspace->forceFill(['plan' => 'business', 'subscription_status' => 'active'])->save();
        $ws = $this->victimWorkspace->id;

        $client = Client::factory()->create(['workspace_id' => $ws, 'name' => self::MARKER.' Client', 'email' => 'victim-client@example.com']);
        $lead = Lead::factory()->create(['workspace_id' => $ws, 'name' => self::MARKER.' Lead', 'email' => 'victim-lead@example.com']);
        $pipeline = Pipeline::withoutGlobalScopes()->where('workspace_id', $ws)->firstOrFail();
        $stage = $pipeline->stages()->withoutGlobalScopes()->firstOrFail();
        $deal = Deal::factory()->create(['workspace_id' => $ws, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'client_id' => $client->id, 'title' => self::MARKER.' Deal']);
        $task = Task::factory()->create(['workspace_id' => $ws, 'title' => self::MARKER.' Task', 'client_id' => $client->id, 'assignee_id' => $this->victim->id]);
        $activity = Activity::create(['workspace_id' => $ws, 'subject_type' => 'client', 'subject_id' => $client->id, 'type' => 'note', 'body' => self::MARKER.' note']);
        $form = Form::factory()->create(['workspace_id' => $ws, 'name' => self::MARKER.' Form']);
        FormSubmission::create(['workspace_id' => $ws, 'form_id' => $form->id, 'lead_id' => $lead->id, 'data' => ['name' => self::MARKER]]);
        $import = Import::create(['workspace_id' => $ws, 'user_id' => $this->victim->id, 'original_name' => self::MARKER.'.csv', 'path' => 'imports/x.csv', 'headers' => ['Név'], 'mapping' => ['0' => 'name']]);
        $rule = AutomationRule::create(['workspace_id' => $ws, 'name' => self::MARKER.' Rule', 'trigger' => 'lead_created', 'action' => 'create_task']);
        $field = CustomFieldDefinition::create(['workspace_id' => $ws, 'entity' => 'client', 'key' => 'victim', 'label' => self::MARKER]);
        $invitation = Invitation::create(['workspace_id' => $ws, 'email' => 'victim-invite@example.com', 'role' => 'member', 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDay()]);
        $invoice = Invoice::create(['workspace_id' => $ws, 'stripe_invoice_id' => 'in_victim', 'currency' => 'HUF', 'net_amount' => 1, 'vat_amount' => 0, 'gross_amount' => 1, 'vat_key' => '27', 'buyer' => ['name' => self::MARKER]]);
        $invoice->forceFill(['pdf_path' => 'invoices/victim.pdf', 'number' => 'V-1'])->save();
        Storage::disk('local')->put('invoices/victim.pdf', self::MARKER);
        $ticket = SupportTicket::create(['workspace_id' => $ws, 'user_id' => $this->victim->id, 'subject' => self::MARKER.' Ticket']);
        $ticket->messages()->create(['user_id' => $this->victim->id, 'body' => self::MARKER]);
        $quote = Quote::factory()->sent()->create(['workspace_id' => $ws, 'title' => self::MARKER.' Quote', 'client_id' => $client->id, 'deal_id' => $deal->id, 'owner_id' => $this->victim->id, 'recipient_email' => 'victim-customer@example.com']);
        $quote->items()->create(['description' => self::MARKER.' item', 'quantity' => 1, 'unit_price' => 1000]);

        $this->victimIds = [
            'client' => $client->id, 'lead' => $lead->id, 'deal' => $deal->id, 'task' => $task->id, 'activity' => $activity->id,
            'form' => $form->id, 'import' => $import->id, 'automationRule' => $rule->id, 'customField' => $field->id,
            'invitation' => $invitation->id, 'invoice' => $invoice->id, 'ticket' => $ticket->id, 'pipeline' => $pipeline->id,
            'user' => $this->victim->id, 'stage' => $stage->id, 'quote' => $quote->id,
        ];
    }

    /**
     * Every workspace scoped API route (the ones behind the "workspace" middleware).
     *
     * @return list<Route>
     */
    protected function workspaceRoutes(): array
    {
        return array_values(array_filter(RouteFacade::getRoutes()->getRoutes(), fn (Route $route) => in_array('workspace', $route->gatherMiddleware(), true)));
    }

    /**
     * Request body full of references to the victim's records.
     *
     * @return array<string, mixed>
     */
    protected function hostilePayload(): array
    {
        $v = $this->victimIds;

        return [
            'name' => 'Attacker', 'title' => 'Attacker', 'subject' => 'Attacker', 'body' => 'Attacker', 'message' => 'Attacker', 'label' => 'Attacker',
            'category' => 'question', 'type' => 'note', 'trigger' => 'lead_created', 'action' => 'create_task', 'role' => 'member', 'email' => 'attacker@example.com',
            'subject_type' => 'client', 'subject_id' => $v['client'],
            'client_id' => $v['client'], 'lead_id' => $v['lead'], 'deal_id' => $v['deal'], 'stage_id' => $v['stage'], 'pipeline_id' => $v['pipeline'], 'form_id' => $v['form'],
            'owner_id' => $v['user'], 'assignee_id' => $v['user'], 'default_owner_id' => $v['user'], 'user_id' => $v['user'],
            'action_config' => ['assignee_id' => $v['user'], 'user_ids' => [$v['user']]],
            'stages' => [['id' => $v['stage'], 'name' => 'Hijacked', 'type' => 'open'], ['name' => 'B', 'type' => 'won']],
            'value' => 1, 'position' => 0, 'status' => 'accepted', 'reason' => 'Attacker',
            'items' => [['description' => 'Attacker', 'unit_price' => 1]], 'follow_up_at' => now()->addDay()->toIso8601String(),
        ];
    }

    protected function urlFor(Route $route): string
    {
        $parameters = [];

        foreach ($route->parameterNames() as $name) {
            $parameters[$name] = $this->victimIds[$name];
        }

        return '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) $parameters[$m[1]], $route->uri()), '/');
    }

    public function test_every_workspace_route_parameter_has_isolation_coverage(): void
    {
        $missing = [];

        foreach ($this->workspaceRoutes() as $route) {
            foreach ($route->parameterNames() as $name) {
                if (! array_key_exists($name, $this->victimIds)) {
                    $missing[] = $route->uri()." {{$name}}";
                }
            }
        }

        $this->assertSame([], $missing, 'Add a victim record for these route parameters to WorkspaceIsolationTest::setUp().');
        $this->assertGreaterThan(60, count($this->workspaceRoutes()));
    }

    public function test_foreign_records_cannot_be_read_changed_or_deleted_through_any_route(): void
    {
        $leaks = [];

        foreach ($this->workspaceRoutes() as $route) {
            if ($route->parameterNames() === []) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->json($method, $this->urlFor($route), $method === 'GET' ? [] : $this->hostilePayload());

                if (! in_array($response->status(), [403, 404], true)) {
                    $leaks[] = "{$method} {$route->uri()} => {$response->status()}";
                }
            }
        }

        $this->assertSame([], $leaks);
        $this->assertVictimUntouched();
    }

    public function test_lists_and_exports_never_contain_foreign_data(): void
    {
        $leaks = [];

        foreach ($this->workspaceRoutes() as $route) {
            if ($route->parameterNames() !== [] || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $response = $this->get('/'.$route->uri(), ['Accept' => 'application/json']);
            $content = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();

            if (str_contains((string) $content, self::MARKER)) {
                $leaks[] = $route->uri();
            }
        }

        foreach (['activities?subject_type=client&subject_id='.$this->victimIds['client'], 'tasks?client_id='.$this->victimIds['client'], 'deals?pipeline_id='.$this->victimIds['pipeline'], 'leads?form_id='.$this->victimIds['form']] as $query) {
            $content = $this->getJson('/api/v1/'.$query)->getContent();
            if (str_contains($content, self::MARKER)) {
                $leaks[] = $query;
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_create_and_update_payloads_cannot_reference_foreign_records(): void
    {
        foreach ($this->workspaceRoutes() as $route) {
            if ($route->parameterNames() !== []) {
                continue;
            }

            foreach (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH']) as $method) {
                $this->json($method, '/'.$route->uri(), $this->hostilePayload());
            }
        }

        $v = $this->victimIds;
        $own = $this->workspace->id;
        $references = [
            'clients' => ['owner_id' => [$v['user']], 'lead_id' => [$v['lead']]],
            'leads' => ['owner_id' => [$v['user']], 'client_id' => [$v['client']], 'form_id' => [$v['form']]],
            'deals' => ['client_id' => [$v['client']], 'lead_id' => [$v['lead']], 'stage_id' => [$v['stage']], 'pipeline_id' => [$v['pipeline']], 'owner_id' => [$v['user']]],
            'tasks' => ['client_id' => [$v['client']], 'lead_id' => [$v['lead']], 'deal_id' => [$v['deal']], 'assignee_id' => [$v['user']]],
            'forms' => ['default_owner_id' => [$v['user']]],
            'quotes' => ['client_id' => [$v['client']], 'lead_id' => [$v['lead']], 'deal_id' => [$v['deal']], 'owner_id' => [$v['user']]],
        ];

        foreach ($references as $table => $columns) {
            foreach ($columns as $column => $ids) {
                $this->assertSame(0, DB::table($table)->where('workspace_id', $own)->whereIn($column, $ids)->count(), "{$table}.{$column} references a foreign record");
            }
        }

        $this->assertSame(0, DB::table('activities')->where('workspace_id', $own)->where('subject_type', 'client')->where('subject_id', $v['client'])->count());
        $this->assertSame(0, DB::table('pipeline_stages')->where('workspace_id', $own)->where('id', $v['stage'])->count());
        $this->assertFalse($this->user->workspaces()->whereKey($this->victimWorkspace->id)->exists());
        $this->assertVictimUntouched();
    }

    public function test_using_the_victims_workspace_header_is_rejected_everywhere(): void
    {
        $this->withHeader('X-Workspace-Id', (string) $this->victimWorkspace->id);
        $accepted = [];

        foreach ($this->workspaceRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $url = $route->parameterNames() === [] ? '/'.$route->uri() : $this->urlFor($route);
                $status = $this->json($method, $url, $method === 'GET' ? [] : $this->hostilePayload())->status();

                if ($status !== 403) {
                    $accepted[] = "{$method} {$route->uri()} => {$status}";
                }
            }
        }

        $this->assertSame([], $accepted);
        $this->assertVictimUntouched();
    }

    public function test_public_form_of_another_workspace_only_creates_data_in_that_workspace(): void
    {
        $form = Form::withoutGlobalScopes()->findOrFail($this->victimIds['form']);

        $this->getJson("/api/v1/public/forms/{$form->slug}")->assertOk()->assertJsonMissingPath('data.workspace_id');
        $this->assertSame(0, Lead::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count());
    }

    protected function assertVictimUntouched(): void
    {
        $ws = $this->victimWorkspace->id;
        $v = $this->victimIds;

        $this->assertSame(self::MARKER.' Client', Client::withoutGlobalScopes()->find($v['client'])?->name);
        $this->assertSame(self::MARKER.' Lead', Lead::withoutGlobalScopes()->find($v['lead'])?->name);
        $this->assertSame(self::MARKER.' Deal', Deal::withoutGlobalScopes()->find($v['deal'])?->title);
        $this->assertSame(self::MARKER.' Task', Task::withoutGlobalScopes()->find($v['task'])?->title);
        $this->assertNotNull(Activity::withoutGlobalScopes()->find($v['activity']));
        $this->assertSame(self::MARKER.' Form', Form::withoutGlobalScopes()->find($v['form'])?->name);
        $this->assertNotNull(Import::withoutGlobalScopes()->find($v['import']));
        $this->assertSame(self::MARKER.' Rule', AutomationRule::withoutGlobalScopes()->find($v['automationRule'])?->name);
        $this->assertNotNull(CustomFieldDefinition::withoutGlobalScopes()->find($v['customField']));
        $this->assertNull(Invitation::find($v['invitation'])?->revoked_at);
        $this->assertSame('open', SupportTicket::find($v['ticket'])?->status);
        $this->assertSame(1, SupportTicket::find($v['ticket'])->messages()->count());
        $this->assertSame(6, DB::table('pipeline_stages')->where('pipeline_id', $v['pipeline'])->count());
        $this->assertNotSame('Hijacked', DB::table('pipeline_stages')->where('id', $v['stage'])->value('name'));
        $this->assertSame(['owner'], $this->victimWorkspace->members()->pluck('workspace_user.role')->all());
        $this->assertSame(self::MARKER.' Kft.', Workspace::find($ws)->name);

        $quote = Quote::withoutGlobalScopes()->find($v['quote']);
        $this->assertSame(self::MARKER.' Quote', $quote?->title);
        $this->assertSame('sent', $quote->status);
        $this->assertSame(1, $quote->items()->count());
        $this->assertSame(0, $quote->view_count);
        $this->assertNull($quote->deleted_at);
    }

    public function test_public_quote_link_exposes_only_that_quote_and_cannot_be_used_from_another_workspace(): void
    {
        $quote = Quote::withoutGlobalScopes()->findOrFail($this->victimIds['quote']);
        $page = $this->getJson('/api/v1/public/quotes/'.$quote->public_token.'?preview=1')->assertOk();

        $page->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.workspace_id')->assertJsonMissingPath('data.client_id')
            ->assertJsonMissingPath('data.deal_id')->assertJsonMissingPath('data.public_token')->assertJsonMissingPath('data.contact.id');

        /* Guessing tokens of another workspace's quotes yields nothing. */
        $this->getJson('/api/v1/public/quotes/'.strrev($quote->public_token))->assertNotFound();
        $this->assertSame(0, Quote::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count());
        $this->assertVictimUntouched();
    }
}
