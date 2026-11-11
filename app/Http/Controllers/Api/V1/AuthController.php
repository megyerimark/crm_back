<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected WorkspaceService $workspaces, protected AuditLogger $audit) {}

    /**
     * Registration creates the user, the first workspace and starts the 14 day Pro trial (no card needed).
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'workspace_name' => ['required_without:invitation_token', 'nullable', 'string', 'max:255'],
            'locale' => ['nullable', Rule::in(['hu', 'en', 'de'])],
            'timezone' => ['nullable', 'timezone:all'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'accept_terms' => ['accepted'],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'locale' => $data['locale'] ?? app()->getLocale(),
                'timezone' => $data['timezone'] ?? null,
            ]);

            if (! empty($data['workspace_name'])) {
                $this->workspaces->create($user, [
                    'name' => $data['workspace_name'],
                    'locale' => $user->locale,
                    'timezone' => $data['timezone'] ?? 'Europe/Budapest',
                    'country' => strtoupper($data['country'] ?? 'HU'),
                    'currency' => strtoupper($data['currency'] ?? 'HUF'),
                ]);
            }

            return $user;
        });

        $this->audit->log('user.registered', $user, [], $user->current_workspace_id);

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('crm.invalid_credentials')]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->tokenResponse($user, 200, $data['device_name'] ?? 'web');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => UserResource::make($user),
            'workspaces' => WorkspaceResource::collection($user->workspaces()->orderBy('name')->get()),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['sometimes', Rule::in(['hu', 'en', 'de'])],
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
        ]);

        $user->update($data);

        return response()->json(['user' => UserResource::make($user)]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password']]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

        return response()->json(['ok' => true]);
    }

    protected function tokenResponse(User $user, int $status, string $device = 'web'): JsonResponse
    {
        $token = $user->createToken($device)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => UserResource::make($user),
            'workspaces' => WorkspaceResource::collection($user->workspaces()->orderBy('name')->get()),
        ], $status);
    }
}
