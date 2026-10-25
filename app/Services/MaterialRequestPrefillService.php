<?php

namespace App\Services;

use App\Mail\MaterialRequestPrefillCodeMail;
use App\Models\ApiClient;
use App\Models\Cohort;
use App\Models\MaterialRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signed portal prefill tokens and the returning-requester OTP flow.
 * Both paths keep personal data private: a token must be signed with the API client secret,
 * and a returning requester must prove control of the email on their last request.
 */
class MaterialRequestPrefillService
{
    public function issue(ApiClient $client, array $payload): string
    {
        $secret = $this->secret($client);
        if (! $secret) {
            throw new \RuntimeException('This API client has no prefill secret.');
        }

        $payload['client_id'] = $client->id;
        $payload['exp'] = now()->addSeconds(config('material-requests.prefill_ttl'))->timestamp;
        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encoded, $secret, true));

        return 'v1.'.$encoded.'.'.$signature;
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            throw ValidationException::withMessages(['prefill' => 'This prefill link is not valid.']);
        }

        $payload = json_decode((string) $this->base64UrlDecode($parts[1]), true);
        if (! is_array($payload) || empty($payload['client_id'])) {
            throw ValidationException::withMessages(['prefill' => 'This prefill link is not valid.']);
        }

        $client = ApiClient::query()->where('active', true)->find($payload['client_id']);
        $secret = $client ? $this->secret($client) : null;
        $expected = $secret ? $this->base64UrlEncode(hash_hmac('sha256', $parts[1], $secret, true)) : null;
        if (! $secret || ! $expected || ! hash_equals($expected, $parts[2])) {
            throw ValidationException::withMessages(['prefill' => 'This prefill link is not valid.']);
        }

        if (! empty($payload['exp']) && now()->timestamp > (int) $payload['exp']) {
            throw ValidationException::withMessages(['prefill' => 'This prefill link has expired.']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{fields: array<string, mixed>, locked: list<string>, id_reuse_token: ?string}
     */
    public function prefillFromPayload(array $payload): array
    {
        $type = $payload['requester_type'] ?? $payload['type'] ?? 'client';
        if (! in_array($type, config('material-requests.requester_types'), true)) {
            $type = 'client';
        }

        $fields = array_filter([
            'full_name' => $payload['full_name'] ?? $payload['name'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'requester_type' => $type,
            'student_alumni_id' => $payload['student_alumni_id'] ?? $payload['student_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $cohortId = $this->resolveCohort($payload['cohort_id'] ?? $payload['cohort'] ?? null);
        if ($cohortId) {
            $fields['cohort_id'] = $cohortId;
        }

        $locked = array_keys($fields);

        $idToken = null;
        $match = $this->findLatestRequest($fields['email'] ?? null, $fields['phone'] ?? null);
        if ($match && $match->id_document_path) {
            $idToken = $this->stageIdReuse($match);
        }

        return [
            'fields' => $fields,
            'locked' => array_values(array_unique($locked)),
            'id_reuse_token' => $idToken,
        ];
    }

    /**
     * @return array{challenge: string, sent_to: ?string}
     */
    public function startOtp(?string $email, ?string $phone): array
    {
        $challenge = (string) Str::uuid();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $match = $this->findLatestRequest($email, $phone);

        Cache::put($this->otpKey($challenge), [
            'request_id' => $match?->id,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
        ], now()->addSeconds(config('material-requests.otp_ttl')));

        if ($match) {
            try {
                Mail::to($match->email)->queue(new MaterialRequestPrefillCodeMail($match, $code));
            } catch (\Throwable $e) {
                // Never reveal whether the contact exists or whether mail is configured.
            }
        }

        // Do not make the response an account-enumeration oracle.
        return ['challenge' => $challenge, 'sent_to' => $email ? $this->maskEmail($email) : null];
    }

    /**
     * @return array{fields: array<string, mixed>, id_reuse_token: ?string}
     */
    public function verifyOtp(string $challenge, string $code): array
    {
        $key = $this->otpKey($challenge);
        $entry = Cache::get($key);
        if (! is_array($entry)) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Request a new one.']);
        }

        $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
        if ($entry['attempts'] > config('material-requests.otp_max_attempts')) {
            Cache::forget($key);
            throw ValidationException::withMessages(['code' => 'Too many attempts. Request a new code.']);
        }
        Cache::put($key, $entry, now()->addSeconds(config('material-requests.otp_ttl')));

        if (! Hash::check($code, (string) $entry['code_hash'])) {
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        $request = MaterialRequest::query()->find($entry['request_id']);
        if (! $request) {
            Cache::forget($key);
            throw ValidationException::withMessages(['code' => 'This code has expired. Request a new one.']);
        }

        Cache::forget($key);

        return [
            'fields' => array_filter([
                'province_res' => $request->province_res,
                'district_res' => $request->district_res,
                'sector_res' => $request->sector_res,
                'cell_res' => $request->cell_res,
                'village_res' => $request->village_res,
                'emergency_contact_name' => $request->emergency_contact_name,
                'emergency_contact_phone' => $request->emergency_contact_phone,
                'relationship' => $request->relationship,
            ], fn ($value) => $value !== null && $value !== ''),
            'id_reuse_token' => $request->id_document_path ? $this->stageIdReuse($request) : null,
        ];
    }

    /** Verify the one-time reuse token against the contact details being submitted. */
    public function consumeIdReuseToken(?string $token, string $email, string $phone): ?MaterialRequest
    {
        if (! $token) {
            return null;
        }

        $requestId = Cache::pull($this->idKey($token));
        if (! $requestId) {
            return null;
        }

        $request = MaterialRequest::query()->find($requestId);
        if (! $request) {
            return null;
        }

        $emailMatches = strtolower((string) $request->email) === strtolower($email);
        $phoneMatches = (string) $request->phone === $phone;

        return $emailMatches || $phoneMatches ? $request : null;
    }

    private function stageIdReuse(MaterialRequest $request): string
    {
        $token = (string) Str::uuid();
        Cache::put($this->idKey($token), $request->id, now()->addSeconds(config('material-requests.prefill_ttl')));

        return $token;
    }

    private function findLatestRequest(?string $email, ?string $phone): ?MaterialRequest
    {
        if (blank($email) && blank($phone)) {
            return null;
        }

        return MaterialRequest::query()
            ->when(filled($email), fn ($q) => $q->whereRaw('LOWER(email) = ?', [strtolower((string) $email)]))
            ->when(filled($phone), fn ($q) => $q->orWhere('phone', $phone))
            ->latest('id')
            ->first();
    }

    private function resolveCohort(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return Cohort::query()->whereKey((int) $value)->value('id');
        }

        return Cohort::query()
            ->where('code', $value)
            ->orWhere('name', $value)
            ->value('id');
    }

    private function secret(ApiClient $client): ?string
    {
        $secret = $client->prefill_secret;
        if (blank($secret)) {
            return null;
        }

        return (string) $secret;
    }

    private function otpKey(string $challenge): string
    {
        return 'material-request-prefill-otp:'.$challenge;
    }

    private function idKey(string $token): string
    {
        return 'material-request-prefill-id:'.$token;
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }
        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).str_repeat('*', max(1, mb_strlen($name) - 1)).'@'.$domain;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
