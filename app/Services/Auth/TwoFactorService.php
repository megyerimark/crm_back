<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Time-based one-time passwords (RFC 6238, SHA-1, 6 digits, 30 s) compatible with Google Authenticator,
 * Microsoft Authenticator, 1Password etc. Secrets and recovery codes are stored encrypted / hashed.
 */
class TwoFactorService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Accept the previous and next time step to tolerate clock drift. */
    private const WINDOW = 1;

    public function generateSecret(): string
    {
        $secret = '';

        for ($i = 0; $i < 32; $i++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        $issuer = config('app.name');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public function qrSvg(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }

    /**
     * Returns the matched time step, or null. Steps at or before $lastUsedStep are rejected (no replay).
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $current = intdiv($timestamp ?? time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $current + $offset;

            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }

            if (hash_equals($this->codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<string> plain recovery codes (shown once); the user stores the hashes
     */
    public function generateRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => encrypt(array_map(fn ($code) => Hash::make($code), $codes))])->save();

        return $codes;
    }

    /**
     * Consumes a recovery code (each code works once).
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ? decrypt($user->two_factor_recovery_codes) : [];
        $code = Str::lower(trim($code));

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => encrypt(array_values($hashes))])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return $user->two_factor_recovery_codes ? count(decrypt($user->two_factor_recovery_codes)) : 0;
    }

    public function secretOf(User $user): ?string
    {
        return $user->two_factor_secret ? decrypt($user->two_factor_secret) : null;
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($secret, '='))) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }
}
