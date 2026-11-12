<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Form;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\Quote;
use App\Models\Task;
use App\Models\User;
use App\Services\Quotes\QuoteService;
use App\Services\WorkspaceService;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data: demo@example.com / password (workspace owner on Pro trial) and admin@example.com / password (super admin).
     */
    public function run(WorkspaceService $workspaces, CurrentWorkspace $current): void
    {
        /* Demo admin without 2FA: set ADMIN_REQUIRE_2FA=false locally, or enable 2FA in the profile. */
        User::factory()->superAdmin()->create(['name' => 'Super Admin', 'email' => 'admin@example.com', 'two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

        $owner = User::factory()->create(['name' => 'Demo Felhasználó', 'email' => 'demo@example.com', 'locale' => 'hu']);
        $workspace = $workspaces->create($owner, ['name' => 'Demo Klímaszerviz Kft.']);
        $current->set($workspace);

        $clients = Client::factory()->count(18)->create(['workspace_id' => $workspace->id, 'owner_id' => $owner->id]);
        $leads = Lead::factory()->count(9)->sequence(
            ['source' => 'form', 'utm_source' => 'facebook', 'utm_medium' => 'social'],
            ['source' => 'form', 'utm_source' => 'google', 'utm_medium' => 'cpc'],
            ['source' => 'manual'],
        )->create(['workspace_id' => $workspace->id, 'owner_id' => $owner->id]);

        $stages = Pipeline::firstOrFail()->stages;

        foreach ($clients->take(10) as $index => $client) {
            $stage = $stages[$index % $stages->count()];
            Deal::factory()->create([
                'workspace_id' => $workspace->id,
                'pipeline_id' => $stage->pipeline_id,
                'stage_id' => $stage->id,
                'client_id' => $client->id,
                'owner_id' => $owner->id,
                'position' => $index,
                'closed_at' => $stage->isClosed() ? now()->subDays($index) : null,
            ]);
        }

        foreach ($clients->take(6) as $index => $client) {
            Task::factory()->create([
                'workspace_id' => $workspace->id,
                'title' => 'Visszahívás: '.$client->name,
                'client_id' => $client->id,
                'assignee_id' => $owner->id,
                'due_at' => now()->addDays($index - 2)->setTime(10, 0),
                'priority' => ['low', 'normal', 'high', 'urgent'][$index % 4],
            ]);

            Activity::create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'subject_type' => 'client',
                'subject_id' => $client->id,
                'type' => ['call', 'email', 'meeting'][$index % 3],
                'body' => 'Egyeztettünk a következő lépésekről.',
                'occurred_at' => now()->subDays($index),
            ]);
        }

        Form::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ajánlatkérés',
            'title' => 'Kérj ajánlatot klímaszerelésre',
            'slug' => 'demo-ajanlatkeres',
            'thank_you_message' => 'Köszönjük! 24 órán belül visszahívunk.',
            'submissions_count' => $leads->where('source', 'form')->count(),
        ]);

        $this->seedQuotes($workspace->id, $owner->id, $clients->take(5)->values());

        $current->clear();
    }

    /**
     * Demo quotes in every tracking state.
     *
     * @param  Collection<int, Client>  $clients
     */
    protected function seedQuotes(int $workspaceId, int $ownerId, $clients): void
    {
        $service = app(QuoteService::class);
        $states = [
            ['Klíma telepítés – családi ház', 'draft', []],
            ['Éves karbantartási szerződés', 'sent', ['sent_at' => now()->subDays(2), 'follow_up_at' => now()->addDay()]],
            ['Irodai hűtés 4 helyiségre', 'viewed', ['sent_at' => now()->subDays(4), 'first_viewed_at' => now()->subDays(3), 'last_viewed_at' => now()->subHours(5), 'view_count' => 3, 'follow_up_at' => now()->subHour()]],
            ['Hőszivattyú csere', 'accepted', ['sent_at' => now()->subDays(9), 'first_viewed_at' => now()->subDays(8), 'view_count' => 2, 'accepted_at' => now()->subDays(7), 'accepted_by_name' => 'Nagy Péter']],
            ['Légtechnikai felmérés', 'declined', ['sent_at' => now()->subDays(12), 'first_viewed_at' => now()->subDays(11), 'view_count' => 1, 'declined_at' => now()->subDays(10), 'decline_reason' => 'Más ajánlatot választottunk.']],
        ];

        foreach ($states as $index => [$title, $status, $timeline]) {
            $client = $clients[$index];
            $quote = Quote::create([
                'workspace_id' => $workspaceId,
                'number' => 'AJ-'.now()->year.'-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'title' => $title,
                'client_id' => $client->id,
                'owner_id' => $ownerId,
                'recipient_name' => $client->name,
                'recipient_email' => $client->email,
                'valid_until' => now()->addDays(14 - $index)->toDateString(),
                'introduction' => 'Köszönjük megkeresését! Az alábbiakban küldjük a felmérés alapján összeállított ajánlatunkat.',
                'terms' => 'Fizetés: 50% előleg, a fennmaradó rész átadáskor. Garancia: 3 év.',
            ]);
            $service->syncItems($quote, [
                ['description' => 'Split klíma 3,5 kW (beltéri + kültéri egység)', 'quantity' => $index + 1, 'unit' => 'db', 'unit_price' => 289000, 'vat_rate' => 27],
                ['description' => 'Telepítés, csövezés 3 m-ig', 'quantity' => $index + 1, 'unit' => 'db', 'unit_price' => 65000, 'vat_rate' => 27],
                ['description' => 'Kiszállás', 'quantity' => 1, 'unit' => 'alkalom', 'unit_price' => 15000, 'vat_rate' => 27],
            ]);
            $quote->forceFill(['status' => $status, 'public_token' => $status === 'draft' ? null : Str::random(48), ...$timeline])->save();
            $quote->events()->create(['type' => 'created', 'created_at' => ($quote->sent_at ?? now())->copy()->subHour()]);
            foreach (['sent' => 'sent_at', 'viewed' => 'first_viewed_at', 'accepted' => 'accepted_at', 'declined' => 'declined_at'] as $type => $column) {
                if ($quote->{$column}) {
                    $quote->events()->create(['type' => $type, 'created_at' => $quote->{$column}]);
                }
            }
        }
    }
}
