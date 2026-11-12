<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketCreated;
use App\Services\PlanService;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer side of the Support Center. Members see their own tickets, workspace admins see all of the workspace.
 */
class SupportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->visibleTickets($request)->withCount('messages')->latest('updated_at');

        return SupportTicketResource::collection($query->paginate($this->perPage($request, 20)));
    }

    public function store(Request $request, PlanService $plans): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $workspace = $this->workspace();
        $plan = $plans->effectivePlan($workspace)->value;

        $ticket = DB::transaction(function () use ($data, $request, $workspace, $plan): SupportTicket {
            $ticket = SupportTicket::create([
                'workspace_id' => $workspace->id,
                'user_id' => $request->user()->id,
                'subject' => $data['subject'],
                'category' => $data['category'],
                'plan' => $plan,
                'priority' => SupportTicket::PLAN_PRIORITY[$plan] ?? 'normal',
            ]);
            $ticket->messages()->create(['user_id' => $request->user()->id, 'body' => $data['message']]);
            $ticket->forceFill(['last_customer_reply_at' => now()])->save();

            return $ticket;
        });

        $notification = new SupportTicketCreated($ticket->load('workspace'), Str::limit($data['message'], 400));
        Notification::send(User::where('is_super_admin', true)->get(), $notification);

        foreach (config('admin.support_emails') as $email) {
            Notification::route('mail', $email)->notify($notification);
        }

        return SupportTicketResource::make($ticket->load('messages.user'))->response()->setStatusCode(201);
    }

    public function show(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        $this->authorizeTicket($request, $ticket);

        return SupportTicketResource::make($ticket->load('messages.user', 'user'));
    }

    public function reply(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        $this->authorizeTicket($request, $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        if ($ticket->status === 'closed') {
            throw ValidationException::withMessages(['body' => __('support.closed')]);
        }

        $ticket->messages()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);
        $ticket->forceFill(['status' => 'open', 'last_customer_reply_at' => now()])->save();

        return SupportTicketResource::make($ticket->load('messages.user', 'user'));
    }

    public function close(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        $this->authorizeTicket($request, $ticket);
        $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        return SupportTicketResource::make($ticket->load('messages.user', 'user'));
    }

    /**
     * @return Builder<SupportTicket>
     */
    protected function visibleTickets(Request $request)
    {
        $query = SupportTicket::query()->where('workspace_id', $this->workspace()->id);

        if (! app(CurrentWorkspace::class)->role()?->atLeast(WorkspaceRole::Admin)) {
            $query->where('user_id', $request->user()->id);
        }

        return $query;
    }

    protected function authorizeTicket(Request $request, SupportTicket $ticket): void
    {
        abort_unless($this->visibleTickets($request)->whereKey($ticket->id)->exists(), 404);
    }
}
