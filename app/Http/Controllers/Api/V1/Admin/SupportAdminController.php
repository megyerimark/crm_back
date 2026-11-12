<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Notifications\SupportTicketReplied;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff side of the Support Center: queue ordered by status and priority (Business first).
 */
class SupportAdminController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with(['workspace', 'user', 'assignee'])->withCount('messages');
        $query->when($request->query('status'), fn ($q, $status) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'closed'))
            ->when($request->query('priority'), fn ($q, $priority) => $q->where('priority', $priority));
        $this->applySearch($query, $request->query('search'), ['subject']);

        /* Waiting on us first, then by priority, then oldest first. */
        $query->orderByRaw("case status when 'open' then 0 when 'pending' then 1 when 'answered' then 2 else 3 end")
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('last_customer_reply_at');

        $page = SupportTicketResource::collection($query->paginate($this->perPage($request)))->response()->getData(true);

        return response()->json($page + ['counts' => SupportTicket::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')]);
    }

    public function show(SupportTicket $ticket): SupportTicketResource
    {
        return SupportTicketResource::make($ticket->load(['messages.user', 'workspace', 'user', 'assignee']));
    }

    public function reply(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['answered', 'pending', 'closed'])],
        ]);

        $ticket->messages()->create(['user_id' => $request->user()->id, 'is_staff' => true, 'body' => $data['body']]);
        $status = $data['status'] ?? 'answered';
        $ticket->forceFill([
            'status' => $status,
            'last_staff_reply_at' => now(),
            'closed_at' => $status === 'closed' ? now() : null,
            'assigned_to' => $ticket->assigned_to ?? $request->user()->id,
        ])->save();

        $ticket->user?->notify(new SupportTicketReplied($ticket, $data['body']));
        $this->audit->log('admin.support.replied', null, ['ticket_id' => $ticket->id], $ticket->workspace_id, true);

        return SupportTicketResource::make($ticket->load(['messages.user', 'workspace', 'user', 'assignee']));
    }

    public function update(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
        ]);

        $ticket->forceFill($data + (($data['status'] ?? null) === 'closed' ? ['closed_at' => now()] : []))->save();
        $this->audit->log('admin.support.updated', null, ['ticket_id' => $ticket->id] + $data, $ticket->workspace_id, true);

        return SupportTicketResource::make($ticket->load(['messages.user', 'workspace', 'user', 'assignee']));
    }
}
