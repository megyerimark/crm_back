<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailAddressChanged;
use App\Notifications\SecurityNotice;
use App\Services\AuditLogger;
use App\Services\Auth\AccountService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E-mail verification, password reset, e-mail change and GDPR export / deletion.
 */
class AccountController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    /**
     * Signed link from the verification e-mail (works without being logged in, e.g. on another device).
     */
    public function verify(string $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => __('account.invalid_link'), 'code' => 'invalid_link'], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            $this->audit->log('account.email_verified', $user, [], $user->current_workspace_id);
        }

        return response()->json(['message' => __('account.verified'), 'verified' => true]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Always answers the same way so the endpoint cannot be used to discover registered addresses.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower($request->input('email'))]);

        return response()->json(['message' => __('account.reset_sent')]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            ['email' => strtolower($data['email']), 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                /* A reset proves control of the mailbox. */
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }
                $user->tokens()->delete();
                $user->notify(new SecurityNotice('password_changed'));
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('account.invalid_link')]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Stores the new address as pending and sends a confirmation link to it. The current address keeps working until then.
     */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email'), Rule::notIn([$user->email])],
            'password' => ['required', 'current_password:sanctum'],
        ]);
        $email = strtolower($data['email']);

        $user->forceFill(['pending_email' => $email])->save();
        $url = $user->frontendSignedUrl('/confirm-email-change', 'api.v1.auth.email-change.confirm', ['id' => $user->id, 'hash' => sha1($email)]);
        Notification::route('mail', $email)->notify((new ConfirmEmailChange($url, $user->name))->locale($user->preferredLocale()));
        $this->audit->log('account.email_change_requested', $user, [], $user->current_workspace_id);

        return response()->json(['user' => UserResource::make($user)]);
    }

    public function confirmEmailChange(string $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if (! $user || ! $user->pending_email || ! hash_equals(sha1($user->pending_email), $hash)) {
            return response()->json(['message' => __('account.invalid_link'), 'code' => 'invalid_link'], 403);
        }

        if (User::where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            return response()->json(['message' => __('validation.unique', ['attribute' => 'email']), 'code' => 'email_taken'], 409);
        }

        $old = $user->email;
        $user->forceFill(['email' => $user->pending_email, 'pending_email' => null, 'email_verified_at' => now()])->save();
        Notification::route('mail', $old)->notify((new EmailAddressChanged($user->name, $user->email))->locale($user->preferredLocale()));
        $this->audit->log('account.email_changed', $user, [], $user->current_workspace_id);

        return response()->json(['message' => __('account.verified'), 'email' => $user->email]);
    }

    public function cancelEmailChange(Request $request): JsonResponse
    {
        $request->user()->forceFill(['pending_email' => null])->save();

        return response()->json(['user' => UserResource::make($request->user())]);
    }

    /**
     * GDPR export as a JSON download (personal data + data of workspaces the user owns).
     */
    public function export(Request $request, AccountService $accounts): StreamedResponse
    {
        $user = $request->user();
        $this->audit->log('account.exported', $user, [], $user->current_workspace_id);
        $payload = $accounts->export($user);

        return response()->streamDownload(
            fn () => print (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'vallalkozoos-export-'.now()->format('Ymd').'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Account deletion: requires the password and the typed confirmation word.
     */
    public function destroy(Request $request, AccountService $accounts): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'current_password:sanctum'],
            'confirmation' => ['required', 'string'],
        ]);

        /* The word shown in the UI depends on the user's language; accept any of the supported ones. */
        $accepted = array_map(fn (string $locale) => mb_strtoupper(trans('account.confirm_word', [], $locale)), ['hu', 'en', 'de']);

        if (! in_array(mb_strtoupper(trim($request->input('confirmation'))), $accepted, true)) {
            throw ValidationException::withMessages(['confirmation' => __('account.confirm_mismatch', ['word' => __('account.confirm_word')])]);
        }

        $accounts->delete($request->user());

        return response()->json(['ok' => true]);
    }
}
