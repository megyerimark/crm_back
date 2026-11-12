<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use App\Services\Auth\TwoFactorService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Optional TOTP two-factor authentication: setup with QR code, confirmation, recovery codes,
 * disabling, and the second login step.
 */
class TwoFactorController extends Controller
{
    /** Seconds a password-verified login may wait for its second factor. */
    public const CHALLENGE_TTL = 300;

    public function __construct(protected TwoFactorService $twoFactor, protected AuditLogger $audit) {}

    /**
     * Generates a new (unconfirmed) secret. 2FA only becomes active after a valid code is confirmed.
     */
    public function setup(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:sanctum']]);
        $user = $request->user();

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        $url = $this->twoFactor->otpauthUrl($user, $secret);

        return response()->json([
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'otpauth_url' => $url,
            'qr_svg' => $this->twoFactor->qrSvg($url),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        $secret = $this->twoFactor->secretOf($user);
        $step = $secret ? $this->twoFactor->verify($secret, $request->input('code')) : null;

        if ($step === null) {
            throw ValidationException::withMessages(['code' => __('account.invalid_code')]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();
        $codes = $this->twoFactor->generateRecoveryCodes($user);
        $user->notify(new SecurityNotice('two_factor_enabled'));
        $this->audit->log('account.two_factor_enabled', $user, [], $user->current_workspace_id);

        return response()->json(['recovery_codes' => $codes, 'user' => UserResource::make($user)]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:sanctum']]);
        abort_unless($request->user()->hasTwoFactorEnabled(), 422);

        return response()->json(['recovery_codes' => $this->twoFactor->generateRecoveryCodes($request->user())]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'current_password:sanctum']]);
        $user = $request->user();

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        $user->notify(new SecurityNotice('two_factor_disabled'));
        $this->audit->log('account.two_factor_disabled', $user, [], $user->current_workspace_id);

        return response()->json(['user' => UserResource::make($user)]);
    }

    /**
     * Second login step: exchanges the short-lived challenge + a TOTP or recovery code for an API token.
     */
    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        try {
            $payload = json_decode(Crypt::decryptString($data['challenge']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['challenge' => __('account.challenge_expired')]);
        }

        $user = User::find($payload['uid'] ?? null);

        if (! $user || ($payload['exp'] ?? 0) < now()->getTimestamp() || ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['challenge' => __('account.challenge_expired')]);
        }

        if (filled($data['recovery_code'] ?? null)) {
            if (! $this->twoFactor->useRecoveryCode($user, $data['recovery_code'])) {
                throw ValidationException::withMessages(['recovery_code' => __('account.invalid_code')]);
            }
            $this->audit->log('account.recovery_code_used', $user, [], $user->current_workspace_id);
        } else {
            $step = $this->twoFactor->verify($this->twoFactor->secretOf($user), (string) $data['code'], $user->two_factor_last_step, now()->getTimestamp());

            if ($step === null) {
                throw ValidationException::withMessages(['code' => __('account.invalid_code')]);
            }

            $user->forceFill(['two_factor_last_step' => $step])->save();
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken($payload['device'] ?? 'web')->plainTextToken,
            'user' => UserResource::make($user),
            'workspaces' => WorkspaceResource::collection($user->workspaces()->orderBy('name')->get()),
        ]);
    }

    public static function makeChallenge(User $user, string $device): string
    {
        return Crypt::encryptString(json_encode(['uid' => $user->id, 'exp' => now()->getTimestamp() + self::CHALLENGE_TTL, 'device' => $device]));
    }
}
