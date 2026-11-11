<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Models\Invitation;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    /**
     * Public lookup so the frontend can show "X invited you to Y" before login/registration.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->find($token);

        return response()->json([
            'data' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'workspace' => $invitation->workspace->name,
                'inviter' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at,
            ],
        ]);
    }

    public function accept(Request $request, string $token, AuditLogger $audit): JsonResponse
    {
        $invitation = $this->find($token);
        $user = $request->user();

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            throw ValidationException::withMessages(['email' => __('crm.invitation_email_mismatch')]);
        }

        DB::transaction(function () use ($invitation, $user): void {
            $invitation->workspace->members()->syncWithoutDetaching([$user->id => ['role' => $invitation->role]]);
            $invitation->forceFill(['accepted_at' => now()])->save();
            $user->forceFill(['current_workspace_id' => $invitation->workspace_id])->save();
        });

        $audit->log('invitation.accepted', $invitation, [], $invitation->workspace_id);

        return response()->json(['data' => WorkspaceResource::make($user->workspaces()->find($invitation->workspace_id))]);
    }

    protected function find(string $token): Invitation
    {
        $invitation = Invitation::with('workspace', 'inviter')->where('token_hash', hash('sha256', $token))->first();

        if (! $invitation || ! $invitation->isPending()) {
            abort(response()->json(['message' => __('crm.invitation_invalid'), 'code' => 'invitation_invalid'], 404));
        }

        return $invitation;
    }
}
