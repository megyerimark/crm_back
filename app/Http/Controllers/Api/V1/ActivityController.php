<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActivityController extends Controller
{
    protected const SUBJECTS = ['client' => Client::class, 'lead' => Lead::class, 'deal' => Deal::class];

    /**
     * Chronological timeline of a client / lead / deal, or the latest activities of the workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'subject_type' => ['nullable', Rule::in(array_keys(self::SUBJECTS))],
            'subject_id' => ['required_with:subject_type', 'integer'],
        ]);

        $query = Activity::query()->with('user')->latest('occurred_at')->latest('id');

        if ($request->filled('subject_type')) {
            $subject = $this->subject($request->query('subject_type'), (int) $request->query('subject_id'));
            $query->whereMorphedTo('subject', $subject);
        } else {
            $query->with('subject');
        }

        $query->when($request->query('type'), fn ($q, $type) => $q->where('type', $type));

        return ActivityResource::collection($query->paginate($this->perPage($request, 30)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(self::SUBJECTS))],
            'subject_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(Activity::MANUAL_TYPES)],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $subject = $this->subject($data['subject_type'], (int) $data['subject_id']);

        if (blank($data['title'] ?? null) && blank($data['body'] ?? null)) {
            throw ValidationException::withMessages(['body' => __('validation.required', ['attribute' => 'body'])]);
        }

        $activity = Activity::create([
            'user_id' => $request->user()->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        if ($subject instanceof Lead && $subject->status === 'new' && in_array($data['type'], ['call', 'email', 'meeting', 'online_meeting'], true)) {
            $subject->update(['status' => 'contacted']);
        }

        return ActivityResource::make($activity->load('user'))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Activity $activity): JsonResponse
    {
        if (! in_array($activity->type, Activity::MANUAL_TYPES, true)) {
            abort(403, __('crm.insufficient_role'));
        }

        $activity->delete();

        return response()->json(['ok' => true]);
    }

    protected function subject(string $type, int $id): Model
    {
        return self::SUBJECTS[$type]::findOrFail($id);
    }
}
