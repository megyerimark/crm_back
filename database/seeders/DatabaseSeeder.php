<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Form;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data: demo@example.com / password (workspace owner on Pro trial) and admin@example.com / password (super admin).
     */
    public function run(WorkspaceService $workspaces, CurrentWorkspace $current): void
    {
        User::factory()->superAdmin()->create(['name' => 'Super Admin', 'email' => 'admin@example.com']);

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

        $current->clear();
    }
}
