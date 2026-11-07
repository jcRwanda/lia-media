<?php

namespace App\Services;

use App\Models\ApiClient;
use Illuminate\Http\Request;

/**
 * Grandfathered alumni may book without a certificate only when an opted-in API client
 * presents a short-lived HMAC over the portal account. Hosted forms, bare booleans, and
 * any other authenticated client fail closed.
 */
class AlumniCertificateExemptVerifier
{
    public const SOURCE = 'grandfathered';

    public const PAYLOAD_PREFIX = 'alumni-certificate-grandfathered';

    /**
     * @return bool True only for a complete, timely, email-matched proof from an opted-in client.
     */
    public function grants(Request $request, ?ApiClient $client, string $bookingEmail): bool
    {
        if (! $this->clientMayExempt($client)) {
            return false;
        }

        $secret = $this->secret($client);
        $parts = $this->proofParts($request);
        if ($secret === null || $parts === null || ! $this->timestampAllowed($parts['at'])) {
            return false;
        }

        $payload = self::PAYLOAD_PREFIX.'|'.$parts['user'].'|'.$parts['email'].'|'.$parts['at'];
        $expected = hash_hmac('sha256', $payload, $secret);
        if (! hash_equals($expected, $parts['sig'])) {
            return false;
        }

        $booking = self::normalizeEmail($bookingEmail);
        $proof = self::normalizeEmail($parts['email']);

        return $booking !== '' && $booking === $proof;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function clientMayExempt(?ApiClient $client): bool
    {
        return $client instanceof ApiClient
            && $client->active
            && (bool) $client->allows_alumni_certificate_exempt;
    }

    private function secret(ApiClient $client): ?string
    {
        try {
            $secret = $client->prefill_secret;
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return $secret;
    }

    /**
     * @return array{user: string, email: string, at: string, sig: string}|null
     */
    private function proofParts(Request $request): ?array
    {
        $user = $this->canonicalUser($request->input('alumni_certificate_exempt_user'));
        $email = $request->input('alumni_certificate_exempt_email');
        $at = $this->canonicalTimestamp($request->input('alumni_certificate_exempt_at'));
        $sig = $this->canonicalSignature($request->input('alumni_certificate_exempt_sig'));

        if ($user === null || ! is_string($email) || $email === '' || strpbrk($email, "\r\n") !== false || $at === null || $sig === null) {
            return null;
        }

        return ['user' => $user, 'email' => $email, 'at' => $at, 'sig' => $sig];
    }

    private function canonicalUser(mixed $user): ?string
    {
        if (is_int($user)) {
            $user = (string) $user;
        }
        if (! is_string($user) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/', $user) !== 1) {
            return null;
        }

        return $user;
    }

    private function canonicalTimestamp(mixed $at): ?string
    {
        if (is_int($at)) {
            $at = (string) $at;
        }
        if (! is_string($at) || preg_match('/\A[1-9][0-9]{9}\z/', $at) !== 1) {
            return null;
        }

        return $at;
    }

    private function canonicalSignature(mixed $sig): ?string
    {
        if (! is_string($sig)) {
            return null;
        }
        $sig = strtolower(trim($sig));
        if (str_starts_with($sig, 'sha256=')) {
            $sig = substr($sig, 7);
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $sig) !== 1) {
            return null;
        }

        return $sig;
    }

    private function timestampAllowed(string $at): bool
    {
        $issued = (int) $at;
        $now = now()->getTimestamp();
        $ttl = max(0, (int) config('material-requests.alumni_certificate_exempt_ttl', 600));
        $skew = max(0, (int) config('material-requests.alumni_certificate_exempt_future_skew', 30));

        if ($issued > $now + $skew) {
            return false;
        }

        return ($now - $issued) <= $ttl;
    }
}
