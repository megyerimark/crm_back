<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\MemberResource;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\WorkspaceInvitation;
use App\Services\AuditLogger;
use App\Services\PlanService;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    public function __construct(protected PlanService $plans, protected AuditLogger $audit) {}

    public function members(): JsonResponse
    {
        return response()->json(['data' => MemberResource::collection($this->workspace()->members()->orderBy('name')->get())]);
    }

    public function updateMember(Request $request, User $user): JsonResponse
    {
        $workspace = $this->workspace();
        $data = $request->validate(['role' => ['required', Rule::in(WorkspaceRole::invitable())]]);
        $member = $workspace->members()->whereKey($user->id)->firstOrFail();

        if ($member->pivot->role === WorkspaceRole::Owner->value) {
            throw ValidationException::withMessages(['role' => __('crm.cannot_remove_owner')]);
        }

        if ($member->pivot->role === WorkspaceRole::Admin->value && app(CurrentWorkspace::class)->role() !== WorkspaceRole::Owner) {
            abort(403, __('crm.insufficient_role'));
        }

        $workspace->members()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $this->audit->log('member.role_changed', $user, ['from' => $member->pivot->role, 'to' => $data['role']]);

        return response()->json(['data' => MemberResource::make($workspace->members()->find($user->id))]);
    }

    public function removeMember(Request $request, User $user): JsonResponse
    {
        $workspace = $this->workspace();
        $member = $workspace->members()->whereKey($user->id)->firstOrFail();

        if ($member->pivot->role === WorkspaceRole::Owner->value) {
            throw ValidationException::withMessages(['user' => __('crm.cannot_remove_owner')]);
        }

        if ($member->pivot->role === WorkspaceRole::Admin->value && app(CurrentWorkspace::class)->role() !== WorkspaceRole::Owner) {
            abort(403, __('crm.insufficient_role'));
        }

        $workspace->members()->detach($user->id);

        if ($user->current_workspace_id === $workspace->id) {
            $user->forceFill(['current_workspace_id' => $user->workspaces()->value('workspaces.id')])->save();
        }

        $this->audit->log('member.removed', $user);

        return response()->json(['ok' => true]);
    }

    /**
     * Owner hands the workspace over to another member (needed before the owner can delete their account).
     */
    public function transferOwnership(Request $request): JsonResponse
    {
        $workspace = $this->workspace();
        abort_unless(app(CurrentWorkspace::class)->role() === WorkspaceRole::Owner, 403, __('crm.insufficient_role'));

        $data = $request->validate(['user_id' => ['required', 'integer', $this->memberRule(), Rule::notIn([$request->user()->id])]]);

        $workspace->members()->updateExistingPivot($request->user()->id, ['role' => WorkspaceRole::Admin->value]);
        $workspace->members()->updateExistingPivot($data['user_id'], ['role' => WorkspaceRole::Owner->value]);
        $workspace->forceFill(['owner_id' => $data['user_id']])->save();
        $this->audit->log('workspace.ownership_transferred', $workspace, ['from' => $request->user()->id, 'to' => $data['user_id']]);

        return response()->json(['data' => MemberResource::collection($workspace->members()->orderBy('name')->get())]);
    }

    public function invitations(): JsonResponse
    {
        $invitations = $this->workspace()->invitations()->with('inviter')->latest()->limit(100)->get();

        return response()->json(['data' => InvitationResource::collection($invitations)]);
    }

    public function invite(Request $request): JsonResponse
    {
        $workspace = $this->workspace();
        $this->plans->ensureFeature($workspace, 'team');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(WorkspaceRole::invitable())],
        ]);
        $email = strtolower($data['email']);

        if ($workspace->members()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('crm.already_member')]);
        }

        /* Re-inviting replaces a pending invitation so it does not count twice against the seat limit. */
        $workspace->invitations()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->plans->ensureWithinLimit($workspace, 'users');

        $token = Str::random(48);
        $invitation = $workspace->invitations()->create([
            'email' => $email,
            'role' => $data['role'],
            'invited_by' => $request->user()->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)->notify((new WorkspaceInvitation($invitation->load('workspace', 'inviter'), $token))->locale($workspace->locale));
        $this->audit->log('invitation.created', $invitation, ['email' => $email, 'role' => $data['role']]);

        return response()->json(['data' => InvitationResource::make($invitation->load('inviter'))], 201);
    }

    public function revoke(Invitation $invitation): JsonResponse
    {
        abort_unless($invitation->workspace_id === $this->workspace()->id, 404);

        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->audit->log('invitation.revoked', $invitation, ['email' => $invitation->email]);

        return response()->json(['data' => InvitationResource::make($invitation)]);
    }
}
