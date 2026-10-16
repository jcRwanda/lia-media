<?php

namespace Tests\E2E;

use App\Models\Asset;
use App\Models\User;
use App\Storage\ObjectStorage;
use App\Storage\R2ObjectStorage;
use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real S3 API end-to-end (presigned PUT with checksum, multipart resume, complete, HEAD, GET).
 * Runs only when E2E_S3_ENDPOINT is set, e.g. against a local SeaweedFS/MinIO container:
 *   E2E_S3_ENDPOINT=http://127.0.0.1:8333 E2E_S3_KEY=... E2E_S3_SECRET=... php artisan test --testsuite=E2E
 */
class S3CompatibleUploadTest extends TestCase
{
    use RefreshDatabase;

    private R2ObjectStorage $s3;

    private Client $http;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('E2E_S3_ENDPOINT')) {
            $this->markTestSkipped('Set E2E_S3_ENDPOINT to run against a real S3-compatible server.');
        }
        $cfg = ['bucket' => 'lia-e2e-'.strtolower(\Illuminate\Support\Str::random(6)), 'key' => getenv('E2E_S3_KEY'), 'secret' => getenv('E2E_S3_SECRET'), 'endpoint' => getenv('E2E_S3_ENDPOINT'), 'region' => 'us-east-1', 'use_path_style' => true];
        $this->s3 = new R2ObjectStorage($cfg);
        $this->s3->client()->createBucket(['Bucket' => $cfg['bucket']]);
        $this->app->instance(ObjectStorage::class, $this->s3);
        $this->http = new Client(['http_errors' => false]);
        $this->bucket = $cfg['bucket'];
    }

    private string $bucket = '';

    /** Remove the throwaway bucket so repeated runs don't exhaust the local server's volumes. */
    protected function tearDown(): void
    {
        if ($this->bucket) {
            $c = $this->s3->client();
            foreach ($c->listObjectsV2(['Bucket' => $this->bucket])['Contents'] ?? [] as $o) {
                $c->deleteObject(['Bucket' => $this->bucket, 'Key' => $o['Key']]);
            }
            foreach ($c->listMultipartUploads(['Bucket' => $this->bucket])['Uploads'] ?? [] as $u) {
                $c->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $u['Key'], 'UploadId' => $u['UploadId']]);
            }
            $c->deleteBucket(['Bucket' => $this->bucket]);
        }
        parent::tearDown();
    }

    public function test_single_put_with_signed_checksum_then_reject_tampered(): void
    {
        $u = User::factory()->role('multimedia')->create();
        $body = random_bytes(200_000);
        $init = $this->actingAs($u)->postJson('/api/uploads', ['filename' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($body), 'sha256' => hash('sha256', $body)])->assertCreated()->json();

        $bad = $this->http->put($init['put']['url'], ['headers' => $init['put']['headers'], 'body' => strrev($body)]);
        $this->assertGreaterThanOrEqual(400, $bad->getStatusCode(), 'tampered bytes must be refused by storage');

        $ok = $this->http->put($init['put']['url'], ['headers' => $init['put']['headers'], 'body' => $body]);
        $this->assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $uuid = $this->actingAs($u)->postJson("/api/uploads/{$init['upload']['uuid']}/complete")->assertOk()->json('asset.uuid');
        $asset = Asset::where('uuid', $uuid)->first();
        $this->assertSame(strlen($body), $this->s3->head($asset->storage_key)['size']);

        $get = $this->http->get($this->s3->presignGet($asset->storage_key, 60, 'photo.jpg'));
        $this->assertSame(hash('sha256', $body), hash('sha256', (string) $get->getBody()));
    }

    public function test_multipart_resume_and_complete_with_server_hash(): void
    {
        config(['media.multipart_threshold' => 5 * 1024 * 1024, 'media.part_size' => 5 * 1024 * 1024]); // S3 minimum part size
        $u = User::factory()->role('multimedia')->create();
        $body = random_bytes(11 * 1024 * 1024); // 3 parts
        $init = $this->actingAs($u)->postJson('/api/uploads', ['filename' => 'clip.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => strlen($body), 'sha256' => hash('sha256', $body)])->assertCreated()->json();
        $id = $init['upload']['uuid'];
        $this->assertSame(3, $init['upload']['part_count']);
        $size = $init['upload']['part_size'];
        $urls = $this->actingAs($u)->postJson("/api/uploads/{$id}/parts", ['part_numbers' => [1, 2, 3]])->json('urls');

        foreach ([1, 3] as $n) { // "network drops" before part 2
            $this->assertSame(200, $this->http->put($urls[$n], ['body' => substr($body, ($n - 1) * $size, $size)])->getStatusCode());
        }
        $this->assertSame([1, 3], array_column($this->actingAs($u)->getJson("/api/uploads/{$id}")->json('parts'), 'part_number'));
        $this->actingAs($u)->postJson("/api/uploads/{$id}/complete")->assertStatus(422);

        $urls = $this->actingAs($u)->postJson("/api/uploads/{$id}/parts", ['part_numbers' => [2]])->json('urls');
        $this->assertSame(200, $this->http->put($urls[2], ['body' => substr($body, $size, $size)])->getStatusCode());
        $uuid = $this->actingAs($u)->postJson("/api/uploads/{$id}/complete")->assertOk()->json('asset.uuid');
        $a = Asset::where('uuid', $uuid)->first();
        $this->assertTrue($a->metadata['checksum_verified'], 'server-side sha256 over the assembled object');
        $this->assertSame(strlen($body), $this->s3->head($a->storage_key)['size']);
    }
}
