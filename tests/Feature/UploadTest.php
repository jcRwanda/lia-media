<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStorage;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase, UsesFakeStorage;

    private User $mm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeStorage();
        $this->mm = User::factory()->role('multimedia')->create();
    }

    private function init(string $body, array $extra = [])
    {
        return $this->actingAs($this->mm)->postJson('/api/uploads', array_merge([
            'filename' => 'Stage Night.JPG', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($body), 'sha256' => hash('sha256', $body),
        ], $extra));
    }

    public function test_single_upload_signs_checksum_verifies_and_creates_asset(): void
    {
        $body = random_bytes(2048);
        $res = $this->init($body, ['context' => ['tags' => ['Stage', 'Cohort 4'], 'width' => 4000, 'height' => 3000]])->assertCreated()
            ->assertJsonPath('upload.mode', 'single')->json();
        $sha64 = base64_encode(hash('sha256', $body, true));
        $this->assertSame($sha64, $res['put']['headers']['x-amz-checksum-sha256']);
        $key = $this->keyOf($res['upload']['uuid']);
        $this->assertMatchesRegularExpression('#^originals/\d{4}/\d{2}/[0-9a-f-]{36}/stage-night\.jpg$#', $key);

        $this->assertTrue($this->r2->simulatePut($key, $body, 'image/jpeg', $sha64));
        $asset = $this->actingAs($this->mm)->postJson("/api/uploads/{$res['upload']['uuid']}/complete")->assertOk()->json('asset');
        $a = Asset::where('uuid', $asset['uuid'])->firstOrFail();
        $this->assertSame('image', $a->kind);
        $this->assertSame('Stage Night', $a->title);
        $this->assertSame(4000, $a->width);
        $this->assertTrue($a->metadata['checksum_verified']);
        $this->assertSame(['cohort-4', 'stage'], $a->tags->pluck('slug')->sort()->values()->all());
        $this->assertTrue(AuditLog::where('action', 'asset.uploaded')->exists());
    }

    public function test_tampered_bytes_are_rejected_by_signed_checksum_and_finalize_fails(): void
    {
        $body = random_bytes(512);
        $res = $this->init($body)->json();
        $key = $this->keyOf($res['upload']['uuid']);
        $this->assertFalse($this->r2->simulatePut($key, $body.'x', 'image/jpeg', $res['put']['headers']['x-amz-checksum-sha256']));
        $this->actingAs($this->mm)->postJson("/api/uploads/{$res['upload']['uuid']}/complete")->assertStatus(422)->assertJsonPath('errors.upload.0', fn ($m) => str_contains($m, 'not found'));
        $this->assertSame('failed', UploadSession::first()->status);
        $this->assertSame(0, Asset::count());
    }

    public function test_size_mismatch_deletes_object(): void
    {
        $body = random_bytes(512);
        $res = $this->init($body)->json();
        $key = $this->keyOf($res['upload']['uuid']);
        $this->r2->simulatePut($key, substr($body, 0, 100), 'image/jpeg');
        $this->actingAs($this->mm)->postJson("/api/uploads/{$res['upload']['uuid']}/complete")->assertStatus(422);
        $this->assertArrayNotHasKey($key, $this->r2->objects);
    }

    public function test_duplicate_detected_before_upload_and_added_to_album(): void
    {
        $body = random_bytes(300);
        $res = $this->init($body)->json();
        $this->r2->simulatePut($this->keyOf($res['upload']['uuid']), $body, 'image/jpeg', $res['put']['headers']['x-amz-checksum-sha256']);
        $this->actingAs($this->mm)->postJson("/api/uploads/{$res['upload']['uuid']}/complete")->assertOk();
        $album = Album::query()->create(['title' => 'Picks', 'created_by' => $this->mm->id]);

        $dup = $this->init($body, ['filename' => 'copy.jpg', 'context' => ['album_id' => $album->id]])->assertOk()->assertJsonPath('status', 'duplicate')->json();
        $this->assertSame(Asset::first()->uuid, $dup['asset']['uuid']);
        $this->assertSame(1, $album->assets()->count());
        $this->assertSame(1, UploadSession::count(), 'no second session / object');
    }

    public function test_parallel_duplicate_resolved_at_finalize(): void
    {
        $body = random_bytes(300);
        $a = $this->init($body)->json();
        $b = $this->init($body, ['filename' => 'other.jpg'])->json(); // both started before either finished
        foreach ([$a, $b] as $r) {
            $this->r2->simulatePut($this->keyOf($r['upload']['uuid']), $body, 'image/jpeg', $r['put']['headers']['x-amz-checksum-sha256']);
        }
        $first = $this->actingAs($this->mm)->postJson("/api/uploads/{$a['upload']['uuid']}/complete")->json('asset.uuid');
        $second = $this->actingAs($this->mm)->postJson("/api/uploads/{$b['upload']['uuid']}/complete")->assertOk()->assertJsonPath('status', 'duplicate')->json('asset.uuid');
        $this->assertSame($first, $second);
        $this->assertSame(1, Asset::count());
        $this->assertArrayNotHasKey($this->keyOf($b['upload']['uuid']), $this->r2->objects);
    }

    public function test_multipart_resume_complete_and_server_checksum_job(): void
    {
        config(['media.multipart_threshold' => 999, 'media.part_size' => 400]);
        $body = random_bytes(1000); // 3 parts: 400 + 400 + 200
        $res = $this->init($body, ['filename' => 'film.mp4', 'mime_type' => 'video/mp4'])->assertCreated()->json();
        $this->assertSame('multipart', $res['upload']['mode']);
        $this->assertSame(3, $res['upload']['part_count']);
        $uuid = $res['upload']['uuid'];
        $upId = UploadSession::first()->upload_id;

        $urls = $this->actingAs($this->mm)->postJson("/api/uploads/{$uuid}/parts", ['part_numbers' => [1, 2, 3]])->assertOk()->json('urls');
        $this->assertCount(3, $urls);
        $this->actingAs($this->mm)->postJson("/api/uploads/{$uuid}/parts", ['part_numbers' => [4]])->assertStatus(422);

        $this->r2->simulatePart($upId, 1, substr($body, 0, 400));
        $this->r2->simulatePart($upId, 3, substr($body, 800));
        // Browser reloads: storage tells it which parts are already there.
        $this->actingAs($this->mm)->getJson("/api/uploads/{$uuid}")->assertOk()->assertJsonCount(2, 'parts')->assertJsonPath('parts.1.part_number', 3);
        $this->actingAs($this->mm)->postJson("/api/uploads/{$uuid}/complete")->assertStatus(422)->assertJsonPath('errors.upload.0', 'Upload is incomplete: 2 of 3 parts received.');

        $this->r2->simulatePart($upId, 2, substr($body, 400, 400));
        $assetUuid = $this->actingAs($this->mm)->postJson("/api/uploads/{$uuid}/complete")->assertOk()->json('asset.uuid');
        $a = Asset::where('uuid', $assetUuid)->first();
        $this->assertSame('video', $a->kind);
        $this->assertSame($body, $this->r2->objects[$a->storage_key]['body']);
        // Queue is sync in tests: the server-side hash already ran.
        $this->assertTrue($a->metadata['checksum_verified']);
        $this->assertSame('server_sha256', $a->metadata['checksum_method']);
        $this->assertSame(3, UploadSession::first()->parts()->count());
    }

    public function test_multipart_corruption_flagged_by_server_hash(): void
    {
        config(['media.multipart_threshold' => 10, 'media.part_size' => 400]);
        $body = random_bytes(500);
        $res = $this->init($body, ['mime_type' => 'video/mp4', 'filename' => 'f.mp4'])->json();
        $upId = UploadSession::first()->upload_id;
        $this->r2->simulatePart($upId, 1, str_repeat('x', 400));
        $this->r2->simulatePart($upId, 2, substr($body, 400));
        $uuid = $this->actingAs($this->mm)->postJson("/api/uploads/{$res['upload']['uuid']}/complete")->assertOk()->json('asset.uuid');
        $a = Asset::where('uuid', $uuid)->first();
        $this->assertFalse($a->metadata['checksum_verified']);
        $this->assertSame('corrupt', $a->storage_status);
        $this->assertTrue(AuditLog::where('action', 'asset.checksum_mismatch')->exists());
    }

    public function test_validation_permissions_ownership_abort_and_prune(): void
    {
        $this->init('x', ['mime_type' => 'image/svg+xml'])->assertStatus(422)->assertJsonValidationErrors('mime_type');
        $this->init('x', ['mime_type' => 'application/x-msdownload'])->assertStatus(422);
        $this->init('x', ['sha256' => 'nothex'])->assertStatus(422)->assertJsonValidationErrors('sha256');
        $this->actingAs(User::factory()->role('communications')->create())->postJson('/api/uploads', [])->assertForbidden();

        $res = $this->init('hello')->json();
        $other = User::factory()->role('multimedia')->create();
        $this->actingAs($other)->getJson("/api/uploads/{$res['upload']['uuid']}")->assertNotFound();
        $this->actingAs($this->mm)->deleteJson("/api/uploads/{$res['upload']['uuid']}")->assertOk();
        $this->assertSame('aborted', UploadSession::first()->status);

        config(['media.multipart_threshold' => 1]);
        $m = $this->init('hello world', ['filename' => 'b.mp4', 'mime_type' => 'video/mp4'])->json();
        UploadSession::where('uuid', $m['upload']['uuid'])->update(['expires_at' => now()->subHour()]);
        $this->artisan('uploads:prune')->expectsOutputToContain('1 expired')->assertSuccessful();
        $this->assertSame('aborted', UploadSession::where('uuid', $m['upload']['uuid'])->value('status'));
        $this->assertContains('abort', array_column($this->r2->calls, 0));
    }

    public function test_browser_generated_derivatives(): void
    {
        $body = random_bytes(100);
        $res = $this->init($body)->json();
        $this->r2->simulatePut($this->keyOf($res['upload']['uuid']), $body, 'image/jpeg', $res['put']['headers']['x-amz-checksum-sha256']);
        $uuid = $this->actingAs($this->mm)->postJson("/api/uploads/{$res['upload']['uuid']}/complete")->json('asset.uuid');

        $this->actingAs($this->mm)->postJson("/api/assets/{$uuid}/derivatives", ['type' => 'thumb', 'mime_type' => 'image/webp', 'width' => 2000, 'height' => 100, 'size_bytes' => 50])->assertStatus(422);
        $this->actingAs($this->mm)->postJson("/api/assets/{$uuid}/derivatives", ['type' => 'poster', 'mime_type' => 'image/webp', 'width' => 400, 'height' => 300, 'size_bytes' => 50])->assertStatus(422);
        $put = $this->actingAs($this->mm)->postJson("/api/assets/{$uuid}/derivatives", ['type' => 'thumb', 'mime_type' => 'image/webp', 'width' => 480, 'height' => 320, 'size_bytes' => 5])->assertCreated()->json('put');
        $this->assertStringContainsString("derivatives/{$uuid}/thumb.webp", $put['url']);
        $this->actingAs($this->mm)->postJson("/api/assets/{$uuid}/derivatives/thumb/complete")->assertStatus(422);
        $this->r2->simulatePut("derivatives/{$uuid}/thumb.webp", '12345', 'image/webp');
        $this->actingAs($this->mm)->postJson("/api/assets/{$uuid}/derivatives/thumb/complete")->assertOk()->assertJsonPath('derivative.status', 'ready');
    }
}
