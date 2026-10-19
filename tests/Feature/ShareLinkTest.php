<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PublicShareController;
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
use ZipArchive;

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

    /** Put known bytes for an asset's original and preview derivative into the fake bucket. */
    private function seedBytes(Asset $asset, string $preview = 'PREVIEW'): void
    {
        $this->r2->simulatePut($asset->storage_key, "ORIG-{$asset->id}", 'image/jpeg');
        $d = $asset->derivatives()->first();
        if ($d) {
            $this->r2->simulatePut($d->storage_key, "{$preview}-{$asset->id}", 'image/webp');
        }
    }

    /** @return array<string, string> zip entry name => content, in archive order */
    private function zipEntries(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $entries;
    }

    public function test_public_link_shows_only_approved_and_published_assets_with_short_lived_urls(): void
    {
        $res = $this->make(['mode' => 'public', 'allow_download' => false]);
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

    public function test_zip_download_streams_only_selected_visible_items_and_audits_each_one(): void
    {
        $res = $this->make(['mode' => 'public', 'allow_download' => true]);
        $token = $this->token($res['url']);
        $this->seedBytes($this->a['approved'], 'APPROVED');
        $this->seedBytes($this->a['published'], 'PUBLISHED');
        $this->seedBytes($this->a['unreviewed'], 'HIDDEN');
        $cookie = $this->grantCookie($this->visit('GET', "/api/s/{$token}")->assertOk());

        $r = $this->visit('POST', "/api/s/{$token}/download", ['items' => [
            $this->a['approved']->uuid,
            $this->a['published']->uuid,
            $this->a['unreviewed']->uuid,
            'not-a-real-uuid',
        ]], $cookie);

        $r->assertOk();
        $this->assertSame('application/zip', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertSame([
            pathinfo($this->a['approved']->original_filename, PATHINFO_FILENAME).'-preview.webp' => 'APPROVED-'.$this->a['approved']->id,
            pathinfo($this->a['published']->original_filename, PATHINFO_FILENAME).'-preview.webp' => 'PUBLISHED-'.$this->a['published']->id,
        ], $this->zipEntries($r->streamedContent()));
        $downloads = AuditLog::query()->where('action', 'share.downloaded')->get();
        $this->assertCount(2, $downloads);
        $this->assertTrue($downloads->every(fn ($l) => $l->actor_type === 'share_link' && $l->properties['zip'] === true));
        $this->assertEqualsCanonicalizing([$this->a['approved']->id, $this->a['published']->id], $downloads->pluck('subject_id')->all());
    }

    public function test_zip_download_requires_downloads_allowed_and_a_live_grant(): void
    {
        $res = $this->make(['mode' => 'public', 'allow_download' => false]);
        $token = $this->token($res['url']);
        $items = ['items' => [$this->a['approved']->uuid]];
        $cookie = $this->grantCookie($this->visit('GET', "/api/s/{$token}")->assertOk()->assertJsonPath('share.allow_download', false));

        $this->visit('POST', "/api/s/{$token}/download", $items, $cookie)->assertForbidden()->assertJsonPath('message', 'Downloads are not enabled for this link.');
        $this->visit('POST', "/api/s/{$token}/download", $items)->assertUnauthorized();
        $this->assertSame(0, AuditLog::query()->where('action', 'share.downloaded')->count());
    }

    public function test_zip_download_rejects_empty_oversized_and_fully_invisible_selections(): void
    {
        $res = $this->make(['mode' => 'public', 'allow_download' => true]);
        $token = $this->token($res['url']);
        $cookie = $this->grantCookie($this->visit('GET', "/api/s/{$token}")->assertOk());

        $this->visit('POST', "/api/s/{$token}/download", ['items' => []], $cookie)->assertUnprocessable();
        $this->visit('POST', "/api/s/{$token}/download", [], $cookie)->assertUnprocessable();
        $this->visit('POST', "/api/s/{$token}/download", ['items' => array_fill(0, PublicShareController::MAX_ZIP_ITEMS + 1, 'x')], $cookie)->assertUnprocessable();
        $this->visit('POST', "/api/s/{$token}/download", ['items' => [$this->a['unreviewed']->uuid]], $cookie)->assertNotFound();
        $this->visit('POST', "/api/s/{$token}/download", ['items' => ['not-a-real-uuid']], $cookie)->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('action', 'share.downloaded')->count());
    }

    public function test_zip_download_sends_original_bytes_when_the_link_includes_originals(): void
    {
        $res = $this->make(['mode' => 'restricted', 'include_originals' => true, 'allow_download' => true, 'recipients' => ['press@example.org']]);
        $this->seedBytes($this->a['approved']);
        [$press] = array_map(fn ($r) => $this->token($r['url']), $res['recipients']);
        $cookie = $this->grantCookie($this->visit('GET', "/api/s/{$press}")->assertOk());

        $r = $this->visit('POST', "/api/s/{$press}/download", ['items' => [$this->a['approved']->uuid]], $cookie)->assertOk();

        $this->assertSame([$this->a['approved']->original_filename => 'ORIG-'.$this->a['approved']->id], $this->zipEntries($r->streamedContent()));
    }

    public function test_new_links_allow_downloads_by_default_and_can_be_turned_off(): void
    {
        $this->assertTrue($this->make(['mode' => 'public'])['share']['allow_download']);
        $this->assertFalse($this->make(['mode' => 'public', 'allow_download' => false])['share']['allow_download']);
        $this->assertTrue(ShareLink::query()->oldest('id')->first()->allow_download, 'the stored row keeps the default');
    }

    public function test_file_endpoint_streams_single_item_bytes_and_audits_the_download(): void
    {
        $res = $this->make(['mode' => 'public', 'allow_download' => true]);
        $token = $this->token($res['url']);
        $this->seedBytes($this->a['approved']);
        $cookie = $this->grantCookie($this->visit('GET', "/api/s/{$token}")->assertOk());

        $r = $this->visit('GET', "/api/s/{$token}/items/{$this->a['approved']->uuid}/file", [], $cookie)->assertOk();
        $this->assertSame('PREVIEW-'.$this->a['approved']->id, $r->streamedContent());
        $this->assertSame('image/webp', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="', (string) $r->headers->get('Content-Disposition'));
        $this->assertSame('no-store, private', $r->headers->get('Cache-Control'));
        $this->assertSame(1, AuditLog::query()->where('action', 'share.downloaded')->count());
    }

    public function test_file_endpoint_respects_visibility_grants_and_the_download_flag(): void
    {
        $res = $this->make(['mode' => 'public', 'allow_download' => true]);
        $token = $this->token($res['url']);
        $cookie = $this->grantCookie($this->visit('GET', "/api/s/{$token}")->assertOk());

        $this->visit('GET', "/api/s/{$token}/items/{$this->a['unreviewed']->uuid}/file", [], $cookie)->assertNotFound();
        $this->visit('GET', "/api/s/{$token}/items/{$this->a['approved']->uuid}/file")->assertUnauthorized();

        $off = $this->make(['mode' => 'public', 'allow_download' => false]);
        $offToken = $this->token($off['url']);
        $offCookie = $this->grantCookie($this->visit('GET', "/api/s/{$offToken}")->assertOk());
        $this->visit('GET', "/api/s/{$offToken}/items/{$this->a['approved']->uuid}/file", [], $offCookie)->assertForbidden();
        $this->assertSame(0, AuditLog::query()->where('action', 'share.downloaded')->count());
    }
}
