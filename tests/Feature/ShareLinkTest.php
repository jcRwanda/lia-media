<?php

namespace Tests\Feature;

use App\Models\AccessGrant;
use App\Models\Album;
use App\Models\Asset;
use App\Models\AssetDerivative;
use App\Models\AuditLog;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\ShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesFakeStorage;
use Tests\TestCase;

class ShareLinkTest extends TestCase
{
    use RefreshDatabase, UsesFakeStorage;

    private User $comms;

    private Album $album;

    /** @var array<string, Asset> */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeStorage();
        $this->comms = User::factory()->role('communications')->create();
        $this->album = Album::query()->create(['title' => 'Graduation', 'created_by' => $this->comms->id]);
        foreach (['approved', 'published', 'unreviewed', 'restricted'] as $state) {
            $asset = Asset::factory()->create(['title' => ucfirst($state), 'review_state' => $state]);
            AssetDerivative::query()->create(['asset_id' => $asset->id, 'type' => 'preview', 'storage_key' => "derivatives/{$asset->uuid}/preview.webp", 'mime_type' => 'image/webp', 'status' => 'ready']);
            $this->album->assets()->attach($asset->id, ['position' => count($this->a) + 1]);
            $this->a[$state] = $asset;
        }
        $withdrawn = Asset::factory()->create(['title' => 'Withdrawn', 'review_state' => 'approved', 'consent_status' => 'withdrawn']);
        $this->album->assets()->attach($withdrawn->id, ['position' => 9]);
    }

    private function make(array $d): array
    {
        return $this->actingAs($this->comms)->postJson('/api/shares', array_merge(['target_type' => 'album', 'target_uuid' => $this->album->uuid], $d))->assertCreated()->json();
    }

    private function token(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    /** Visitor request without the staff session. */
    private function visit(string $method, string $uri, array $data = [], array $cookies = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this->withCredentials()->withUnencryptedCookies($cookies)->json($method, $uri, $data);
    }

    private function grantCookie(TestResponse $r): array
    {
        foreach ($r->headers->getCookies() as $c) {
            if ($c->getName() === ShareService::COOKIE) {
                $this->assertTrue($c->isHttpOnly());
                $this->assertStringStartsWith('/api/s/', $c->getPath());

                return [$c->getName() => $c->getValue()];
            }
        }
        $this->fail('no grant cookie');
    }

    public function test_public_link_shows_only_approved_and_published_assets_with_short_lived_urls(): void
    {
        $res = $this->make(['mode' => 'public']);
        $token = $this->token($res['url']);
        $this->assertSame(hash('sha256', $token), ShareLink::first()->token_hash, 'only the hash is stored');
        $this->assertSame(2, $res['share']['visible_count']);

        $r = $this->visit('GET', "/api/s/{$token}")->assertOk()->assertJsonPath('requires_code', false)->assertJsonCount(2, 'items')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertEqualsCanonicalizing(['Approved', 'Published'], array_column($r->json('items'), 'title'));
        $this->assertStringContainsString('X-Amz-Expires=300', $r->json('items.0.preview_url'));
        $this->assertArrayNotHasKey('storage_key', $r->json('items.0'));
        $this->assertSame(1, ShareLink::first()->use_count);

        $cookie = $this->grantCookie($r);
        $this->visit('GET', "/api/s/{$token}", [], $cookie)->assertOk();
        $this->assertSame(1, ShareLink::first()->fresh()->use_count, 'returning visitor is not counted twice');
        $this->visit('POST', "/api/s/{$token}/items/{$this->a['approved']->uuid}/download", [], $cookie)->assertForbidden();
        $this->visit('GET', '/api/s/'.str_repeat('x', 43))->assertNotFound();
    }

    public function test_access_code_is_hashed_rate_limited_and_grants_temporary_access(): void
    {
        $res = $this->make(['mode' => 'access_code', 'allow_download' => true]);
        $code = $res['access_code'];
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $code);
        $this->assertNotSame($code, ShareLink::first()->access_code_hash);
        $token = $this->token($res['url']);

        $this->visit('GET', "/api/s/{$token}")->assertOk()->assertJsonPath('requires_code', true)->assertJsonMissingPath('items');
        foreach (range(1, 5) as $i) {
            $this->visit('POST', "/api/s/{$token}/unlock", ['code' => 'WRONGCODE'])->assertStatus(422);
        }
        $this->visit('POST', "/api/s/{$token}/unlock", ['code' => $code])->assertStatus(429);
        $this->assertSame(5, DB::table('share_access_attempts')->where('succeeded', false)->count());
        $this->assertTrue(AuditLog::where('action', 'share.code_locked')->exists());

        $this->travel(16)->minutes();
        $u = $this->visit('POST', "/api/s/{$token}/unlock", ['code' => strtolower($code)])->assertOk();
        $cookie = $this->grantCookie($u);
        $this->visit('GET', "/api/s/{$token}", [], $cookie)->assertOk()->assertJsonCount(2, 'items');
        $url = $this->visit('POST', "/api/s/{$token}/items/{$this->a['published']->uuid}/download", [], $cookie)->assertOk()->json('url');
        $this->assertStringContainsString('preview.webp', $url, 'code links never hand out originals');
        $this->visit('POST', "/api/s/{$token}/items/{$this->a['unreviewed']->uuid}/download", [], $cookie)->assertNotFound();

        $this->travel(ShareService::GRANT_MINUTES + 1)->minutes();
        $this->visit('GET', "/api/s/{$token}", [], $cookie)->assertJsonPath('requires_code', true);
    }

    public function test_restricted_links_are_personal_individually_revocable_and_may_include_originals(): void
    {
        $this->actingAs($this->comms)->postJson('/api/shares', ['target_type' => 'album', 'target_uuid' => $this->album->uuid, 'mode' => 'public', 'include_originals' => true])->assertStatus(422);
        $this->actingAs($this->comms)->postJson('/api/shares', ['target_type' => 'album', 'target_uuid' => $this->album->uuid, 'mode' => 'restricted'])->assertStatus(422);
        $res = $this->make(['mode' => 'restricted', 'include_originals' => true, 'allow_download' => true, 'recipients' => ['Press@Example.com', 'partner@example.org']]);
        $this->assertNull($res['url']);
        $this->assertCount(2, $res['recipients']);
        [$press, $partner] = array_map(fn ($r) => $this->token($r['url']), $res['recipients']);

        $r = $this->visit('GET', "/api/s/{$press}")->assertOk()->assertJsonCount(2, 'items');
        $cookie = $this->grantCookie($r);
        $orig = $this->visit('POST', "/api/s/{$press}/items/{$this->a['approved']->uuid}/download", [], $cookie)->assertOk()->json('url');
        $this->assertStringContainsString($this->a['approved']->storage_key, $orig);
        $this->assertTrue(AuditLog::where('action', 'share.downloaded')->where('actor_type', 'share_link')->exists());

        $share = ShareLink::first();
        $rid = $share->recipients()->where('email', 'press@example.com')->value('id');
        $this->actingAs($this->comms)->deleteJson("/api/shares/{$share->uuid}/recipients/{$rid}")->assertOk();
        $this->visit('GET', "/api/s/{$press}", [], $cookie)->assertStatus(410);
        $this->visit('GET', "/api/s/{$partner}")->assertOk();
    }

    public function test_revoke_expiry_max_uses_rotate_and_staff_view(): void
    {
        $res = $this->make(['mode' => 'public', 'max_uses' => 1]);
        $token = $this->token($res['url']);
        $cookie = $this->grantCookie($this->visit('GET', "/api/s/{$token}")->assertOk());
        $this->visit('GET', "/api/s/{$token}")->assertStatus(410);
        $this->visit('GET', "/api/s/{$token}", [], $cookie)->assertOk();

        $share = ShareLink::first();
        $show = $this->actingAs($this->comms)->getJson("/api/shares/{$share->uuid}")->assertOk()->assertJsonPath('share.status', 'used_up')->assertJsonPath('url_ttl_seconds', 300)->json();
        $this->assertNotEmpty($show['log']);

        $new = $this->token($this->actingAs($this->comms)->postJson("/api/shares/{$share->uuid}/rotate")->assertOk()->json('url'));
        $this->visit('GET', "/api/s/{$token}", [], $cookie)->assertNotFound();
        $this->assertNotSame($token, $new);

        $this->actingAs($this->comms)->postJson("/api/shares/{$share->uuid}/revoke")->assertOk()->assertJsonPath('share.status', 'revoked');
        $this->visit('GET', "/api/s/{$new}")->assertStatus(410);
        $this->assertSame(0, AccessGrant::query()->whereNull('revoked_at')->count());

        $exp = $this->make(['mode' => 'public', 'expires_at' => now()->addDay()->toIso8601String()]);
        $this->travel(2)->days();
        $this->visit('GET', '/api/s/'.$this->token($exp['url']))->assertStatus(410)->assertJsonPath('message', 'This link has expired.');
        $this->actingAs(User::factory()->role('management')->create())->getJson('/api/shares')->assertForbidden();
    }
}
