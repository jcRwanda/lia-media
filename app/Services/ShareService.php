<?php

namespace App\Services;

use App\Models\AccessGrant;
use App\Models\Album;
use App\Models\Asset;
use App\Models\ShareLink;
use App\Models\ShareRecipient;
use App\Models\User;
use App\Storage\ObjectStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Share links. Tokens and access codes are stored only as hashes. Every visitor gets a short
 * temporary grant (cookie); media is served through presigned URLs that expire within minutes,
 * so revoking a link stops new URLs immediately and already-issued ones within the URL TTL.
 */
class ShareService
{
    public const GRANT_MINUTES = 120;

    public const CODE_ATTEMPTS_PER_IP = 5;      // per link, per 15 minutes

    public const CODE_ATTEMPTS_PER_LINK = 20;   // all IPs, per hour

    public function __construct(private ObjectStorage $storage, private AuditLogger $audit, private AssetPresenter $present) {}

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** @return array{link: ShareLink, token: string, code: ?string, recipients: list<array{email: string, token: string}>} */
    public function create(User $actor, Album|Asset $target, array $d): array
    {
        if ($d['mode'] !== 'restricted' && ! empty($d['include_originals'])) {
            throw ValidationException::withMessages(['include_originals' => 'Originals can only be shared with named recipients (restricted links).']);
        }
        if ($d['mode'] === 'restricted' && empty($d['recipients'])) {
            throw ValidationException::withMessages(['recipients' => 'Add at least one recipient email.']);
        }
        $token = self::newToken();
        $code = $d['mode'] === 'access_code' ? strtoupper(trim($d['access_code'] ?? '')) ?: self::newCode() : null;
        if ($code !== null && strlen($code) < 6) {
            throw ValidationException::withMessages(['access_code' => 'Access codes need at least 6 characters.']);
        }

        return DB::transaction(function () use ($actor, $target, $d, $token, $code) {
            $link = ShareLink::query()->create([
                'token_hash' => ShareLink::hashToken($token), 'shareable_type' => $target instanceof Album ? 'album' : 'asset', 'shareable_id' => $target->id,
                'mode' => $d['mode'], 'access_code_hash' => $code ? Hash::make($code) : null, 'title' => $d['title'] ?? $target->title, 'message' => $d['message'] ?? null,
                'allow_download' => (bool) ($d['allow_download'] ?? false), 'include_originals' => (bool) ($d['include_originals'] ?? false),
                'expires_at' => array_key_exists('expires_at', $d) ? $d['expires_at'] : now()->addDays(30), 'max_uses' => $d['max_uses'] ?? null, 'created_by' => $actor->id,
            ]);
            $recipients = [];
            foreach (array_unique(array_map('strtolower', $d['recipients'] ?? [])) as $email) {
                $recipients[] = $this->addRecipient($link, $email);
            }
            $this->audit->log('share.created', $link, ['mode' => $link->mode, 'target' => $link->shareable_type.':'.$target->uuid, 'recipients' => count($recipients), 'expires_at' => $link->expires_at?->toIso8601String()], $actor);

            return ['link' => $link, 'token' => $token, 'code' => $code, 'recipients' => $recipients];
        });
    }

    /** @return array{email: string, token: string} */
    public function addRecipient(ShareLink $link, string $email, ?string $name = null): array
    {
        $token = self::newToken();
        $link->recipients()->updateOrCreate(['email' => strtolower($email)], ['name' => $name, 'token_hash' => ShareLink::hashToken($token), 'revoked_at' => null]);

        return ['email' => strtolower($email), 'token' => $token];
    }

    public function revoke(ShareLink $link, User $actor): void
    {
        $link->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
        $link->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->audit->log('share.revoked', $link, [], $actor);
    }

    public function rotate(ShareLink $link, User $actor): string
    {
        $token = self::newToken();
        $link->update(['token_hash' => ShareLink::hashToken($token)]);
        $link->grants()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->audit->log('share.rotated', $link, [], $actor);

        return $token;
    }

    /** Resolve a raw token: the link's own token, or a restricted recipient's personal token. */
    public function resolve(string $token): array
    {
        $hash = ShareLink::hashToken($token);
        $link = ShareLink::query()->where('token_hash', $hash)->first();
        $recipient = null;
        if (! $link && ($recipient = ShareRecipient::query()->where('token_hash', $hash)->first())) {
            $link = $recipient->shareLink;
        }
        abort_unless($link, 404, 'This link does not exist.');
        abort_if($link->mode === 'restricted' && ! $recipient, 403, 'This link is personal. Use the link that was sent to you.');
        abort_if($recipient?->revoked_at, 410, 'Your access to this link was removed.');
        abort_unless($link->revoked_at === null, 410, 'This link was revoked.');
        abort_if($link->expires_at && $link->expires_at->isPast(), 410, 'This link has expired.');

        return [$link, $recipient];
    }

    /** One cookie name; the cookie path (/api/s/{token}) keeps grants for different links apart. */
    public const COOKIE = 'lia_share_grant';

    public static function cookieName(ShareLink $link): string
    {
        return self::COOKIE;
    }

    public function currentGrant(ShareLink $link, Request $request): ?AccessGrant
    {
        $raw = $request->cookie(self::cookieName($link));
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $g = AccessGrant::query()->where('grant_hash', hash('sha256', $raw))->where('share_link_id', $link->id)->first();

        return $g && $g->isValid() ? $g : null;
    }

    /** Issue a grant (counts as one use). @return array{0: AccessGrant, 1: string} */
    public function issueGrant(ShareLink $link, Request $request, ?ShareRecipient $recipient = null): array
    {
        if ($link->max_uses && $link->use_count >= $link->max_uses) {
            abort(410, 'This link has reached its visitor limit.');
        }
        $raw = self::newToken();
        $grant = $link->grants()->create(['share_recipient_id' => $recipient?->id, 'grant_hash' => hash('sha256', $raw), 'ip' => $request->ip(), 'expires_at' => now()->addMinutes(self::GRANT_MINUTES)]);
        $link->increment('use_count');
        $link->forceFill(['last_accessed_at' => now()])->save();
        $recipient?->forceFill(['last_accessed_at' => now()])->save();
        $this->audit->log('share.opened', $link, ['recipient' => $recipient?->email, 'grant' => $grant->id], null, 'share_link');

        return [$grant, $raw];
    }

    /** Check an access code with per-IP and per-link throttling. */
    public function unlock(ShareLink $link, Request $request, string $code): array
    {
        abort_unless($link->mode === 'access_code', 400, 'This link does not use an access code.');
        $ipKey = "share-code:{$link->id}:".$request->ip();
        $linkKey = "share-code-link:{$link->id}";
        if (RateLimiter::tooManyAttempts($ipKey, self::CODE_ATTEMPTS_PER_IP) || RateLimiter::tooManyAttempts($linkKey, self::CODE_ATTEMPTS_PER_LINK)) {
            $wait = max(RateLimiter::availableIn($ipKey), RateLimiter::availableIn($linkKey));
            $this->audit->log('share.code_locked', $link, ['ip' => $request->ip()], null, 'share_link');
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.max(1, (int) ceil($wait / 60)).' minute(s).'])->status(429);
        }
        $ok = Hash::check(strtoupper(trim($code)), (string) $link->access_code_hash);
        DB::table('share_access_attempts')->insert(['share_link_id' => $link->id, 'ip' => $request->ip(), 'succeeded' => $ok, 'created_at' => now()]);
        if (! $ok) {
            RateLimiter::hit($ipKey, 15 * 60);
            RateLimiter::hit($linkKey, 60 * 60);
            $this->audit->log('share.code_failed', $link, ['ip' => $request->ip()], null, 'share_link');
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }
        RateLimiter::clear($ipKey);

        return $this->issueGrant($link, $request);
    }

    /** Assets a visitor may see: approved/published, consent not withdrawn, not deleted, storage intact. */
    public function visibleAssets(ShareLink $link): Collection
    {
        $q = $link->shareable_type === 'album'
            ? Album::query()->findOrFail($link->shareable_id)->assets()
            : Asset::query()->whereKey($link->shareable_id);

        return $q->with('derivatives')->whereIn('review_state', Asset::SHAREABLE_STATES)->where('consent_status', '!=', 'withdrawn')->where('storage_status', 'stored')->get();
    }

    public function visitorItem(ShareLink $link, Asset $a): array
    {
        return [
            'uuid' => $a->uuid, 'kind' => $a->kind, 'title' => $a->title, 'width' => $a->width, 'height' => $a->height, 'credit' => $a->credit,
            'thumb_url' => $this->present->derivUrl($a, 'thumb'),
            'preview_url' => $this->present->derivUrl($a, 'preview') ?? $this->present->derivUrl($a, 'poster') ?? $this->present->derivUrl($a, 'thumb'),
        ];
    }

    public function downloadUrl(ShareLink $link, Asset $a, Request $request): string
    {
        abort_unless($link->allow_download, 403, 'Downloads are not enabled for this link.');
        $ttl = config('media.ttl.download');
        if ($link->include_originals) {
            $url = $this->storage->presignGet($a->storage_key, $ttl, $a->original_filename, false);
        } else {
            $d = $a->derivatives->firstWhere('type', 'preview') ?? $a->derivatives->firstWhere('type', 'poster');
            abort_unless($d && $d->status === 'ready', 404, 'No downloadable version yet.');
            $url = $this->storage->presignGet($d->storage_key, $ttl, pathinfo($a->original_filename, PATHINFO_FILENAME).'-preview.'.pathinfo($d->storage_key, PATHINFO_EXTENSION), false);
        }
        $this->audit->log('share.downloaded', $a, ['share' => $link->uuid, 'original' => $link->include_originals, 'ip' => $request->ip()], null, 'share_link');

        return $url;
    }

    public function staffPayload(ShareLink $l): array
    {
        $target = $l->target();

        return [
            'uuid' => $l->uuid, 'mode' => $l->mode, 'title' => $l->title, 'message' => $l->message, 'allow_download' => $l->allow_download, 'include_originals' => $l->include_originals,
            'expires_at' => $l->expires_at?->toIso8601String(), 'max_uses' => $l->max_uses, 'use_count' => $l->use_count, 'revoked_at' => $l->revoked_at?->toIso8601String(),
            'last_accessed_at' => $l->last_accessed_at?->toIso8601String(), 'created_at' => $l->created_at?->toIso8601String(), 'created_by' => $l->creator?->name,
            'status' => $l->revoked_at ? 'revoked' : ($l->expires_at?->isPast() ? 'expired' : ($l->max_uses && $l->use_count >= $l->max_uses ? 'used_up' : 'active')),
            'target' => ['type' => $l->shareable_type, 'uuid' => $target?->uuid, 'title' => $target?->title],
            'visible_count' => $target ? $this->visibleAssets($l)->count() : 0,
        ];
    }
}
