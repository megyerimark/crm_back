<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DealStageChange;
use App\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_a_deal_logs_the_stage_change(): void
    {
        $this->signIn();
        $client = Client::factory()->create(['workspace_id' => $this->workspace->id]);
        $stages = Pipeline::firstOrFail()->stages;

        $deal = $this->postJson('/api/v1/deals', ['title' => 'Klímaszerelés', 'stage_id' => $stages[0]->id, 'client_id' => $client->id, 'value' => 450000])
            ->assertCreated()->assertJsonPath('data.currency', 'HUF')->json('data');

        $this->postJson("/api/v1/deals/{$deal['id']}/move", ['stage_id' => $stages[4]->id, 'position' => 0])
            ->assertOk()->assertJsonPath('data.stage_id', $stages[4]->id);

        $this->assertNotNull($this->getJson("/api/v1/deals/{$deal['id']}")->json('data.closed_at'));
        $this->assertSame(2, DealStageChange::count());
        $this->getJson("/api/v1/activities?subject_type=client&subject_id={$client->id}")->assertJsonPath('data.0.type', 'stage_change');
    }

    public function test_pipeline_count_is_limited_by_plan(): void
    {
        $this->signIn('free');

        $this->postJson('/api/v1/pipelines', ['name' => 'Második'])->assertStatus(402);
    }

    public function test_pro_can_create_custom_pipeline(): void
    {
        $this->signIn('pro');

        $this->postJson('/api/v1/pipelines', [
            'name' => 'Webfejlesztés',
            'stages' => [['name' => 'Brief', 'type' => 'open'], ['name' => 'Kész', 'type' => 'won'], ['name' => 'Elúszott', 'type' => 'lost']],
        ])->assertCreated()->assertJsonCount(3, 'data.stages');
    }
}
