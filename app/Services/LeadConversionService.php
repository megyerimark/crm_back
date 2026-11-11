<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\FormSubmission;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Converts a lead into a client while keeping its history (submissions, activities, tasks).
 */
class LeadConversionService
{
    public function __construct(
        protected PlanService $plans,
        protected ActivityLogger $activities,
        protected AuditLogger $audit,
        protected AutomationService $automations,
    ) {}

    /**
     * @param  array{client_id?: int|null, type?: string, status?: string}  $options
     */
    public function convert(Workspace $workspace, Lead $lead, array $options = []): Client
    {
        if ($lead->isConverted()) {
            throw ValidationException::withMessages(['lead' => __('crm.lead_already_converted')]);
        }

        $existingClientId = $options['client_id'] ?? null;

        if (! $existingClientId) {
            $this->plans->ensureWithinLimit($workspace, 'clients');
        }

        $created = false;

        $client = DB::transaction(function () use ($lead, $options, $existingClientId, &$created): Client {
            if ($existingClientId) {
                $client = Client::findOrFail($existingClientId);
            } else {
                $client = Client::create([
                    'name' => $lead->name,
                    'company' => $lead->company,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'type' => $options['type'] ?? ($lead->company ? 'company' : 'person'),
                    'status' => $options['status'] ?? 'active',
                    'tags' => $lead->tags,
                    'source' => $lead->source,
                    'owner_id' => $lead->owner_id,
                    'lead_id' => $lead->id,
                    'notes' => $lead->message,
                ]);
                $created = true;
            }

            $lead->forceFill([
                'status' => 'converted',
                'client_id' => $client->id,
                'converted_at' => now(),
                'converted_by' => auth()->id(),
            ])->save();

            Task::where('lead_id', $lead->id)->whereNull('client_id')->update(['client_id' => $client->id]);
            Deal::where('lead_id', $lead->id)->whereNull('client_id')->update(['client_id' => $client->id]);

            /* Copy the lead's history onto the client timeline, the lead keeps its own copy. */
            foreach (Activity::where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->get() as $activity) {
                $copy = $activity->replicate();
                $copy->subject_type = $client->getMorphClass();
                $copy->subject_id = $client->id;
                $copy->meta = array_merge($activity->meta ?? [], ['copied_from_lead' => $lead->id]);
                $copy->save();
            }

            $submissions = FormSubmission::where('lead_id', $lead->id)->count();

            $this->activities->log($lead, 'conversion', __('crm.activity.lead_converted'), null, ['client_id' => $client->id]);
            $this->activities->log($client, 'conversion', __('crm.activity.created_from_lead'), null, ['lead_id' => $lead->id, 'submissions' => $submissions]);
            $this->audit->log('lead.converted', $lead, ['client_id' => $client->id]);

            return $client;
        });

        if ($created) {
            $this->automations->fire($workspace, 'client_created', $client);
        }

        return $client;
    }
}
