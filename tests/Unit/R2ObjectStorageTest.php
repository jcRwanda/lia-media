<?php

namespace Tests\Unit;

use App\Storage\R2ObjectStorage;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** The R2 adapter against a mocked S3 API (no network). */
class R2ObjectStorageTest extends TestCase
{
    private MockHandler $mock;

    private R2ObjectStorage $r2;

    /** @var list<CommandInterface> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->mock = new MockHandler;
        $client = new S3Client([
            'version' => 'latest', 'region' => 'auto', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'AKIDEXAMPLE', 'secret' => 'secret'], 'handler' => function (CommandInterface $cmd, $req) {
                $this->commands[] = $cmd;

                return ($this->mock)($cmd, $req);
            },
        ]);
        $this->r2 = new R2ObjectStorage(['bucket' => 'lia-media', 'key' => 'k', 'secret' => 's', 'endpoint' => 'x', 'region' => 'auto', 'use_path_style' => true], $client);
    }

    public function test_presigned_put_signs_content_type_and_checksum(): void
    {
        $sha = base64_encode(hash('sha256', 'abc', true));
        $put = $this->r2->presignPut('originals/a.jpg', 'image/jpeg', 3, 900, $sha);
        $q = [];
        parse_str(parse_url($put['url'], PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('https://acct.r2.cloudflarestorage.com/lia-media/originals/a.jpg?', $put['url']);
        $this->assertSame('900', $q['X-Amz-Expires']);
        $this->assertStringContainsString('x-amz-checksum-sha256', $q['X-Amz-SignedHeaders']);
        // The SDK does not sign Content-Type on presigned PUTs; the checksum is what binds the bytes.
        $this->assertSame('image/jpeg', $put['headers']['Content-Type']);
        $this->assertSame($sha, $put['headers']['x-amz-checksum-sha256']);
    }

    public function test_presigned_get_sets_disposition_and_ttl(): void
    {
        $this->mock->append(new Result(['ContentLength' => 3, 'ContentType' => 'image/jpeg']));

        $url = $this->r2->presignGet('originals/a.jpg', 300, 'My "file".jpg', false);
        $this->assertStringContainsString('X-Amz-Expires=300', $url);
        $this->assertStringContainsString('response-content-type=image%2Fjpeg', $url);
        $this->assertStringContainsString('response-content-disposition=attachment%3B%20filename%3D%22My%20file.jpg%22', $url);
    }

    public function test_presigned_get_without_a_stored_object_still_signs(): void
    {
        $this->mock->append(function (CommandInterface $cmd) {
            return new S3Exception('Not Found', $cmd, ['code' => 'NotFound', 'response' => new Response(404)]);
        });

        $url = $this->r2->presignGet('originals/missing.jpg', 300);

        $this->assertStringContainsString('X-Amz-Expires=300', $url);
        $this->assertStringNotContainsString('response-content-type', $url);
    }

    public function test_multipart_lifecycle_and_list_parts_pagination(): void
    {
        $this->mock->append(new Result(['UploadId' => 'U1']));
        $this->assertSame('U1', $this->r2->createMultipart('originals/v.mp4', 'video/mp4'));
        $this->assertStringContainsString('partNumber=2', $this->r2->presignPart('originals/v.mp4', 'U1', 2, 600));
        $this->assertStringContainsString('uploadId=U1', $this->r2->presignPart('originals/v.mp4', 'U1', 2, 600));

        $this->mock->append(new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"e1"', 'Size' => 5]], 'IsTruncated' => true, 'NextPartNumberMarker' => 1]));
        $this->mock->append(new Result(['Parts' => [['PartNumber' => 2, 'ETag' => '"e2"', 'Size' => 3]], 'IsTruncated' => false]));
        $this->assertSame([['part_number' => 1, 'etag' => 'e1', 'size' => 5], ['part_number' => 2, 'etag' => 'e2', 'size' => 3]], $this->r2->listParts('originals/v.mp4', 'U1'));

        $this->mock->append(new Result([]));
        $this->r2->completeMultipart('originals/v.mp4', 'U1', [['part_number' => 2, 'etag' => 'e2'], ['part_number' => 1, 'etag' => 'e1']]);
        $complete = end($this->commands);
        $this->assertSame('CompleteMultipartUpload', $complete->getName());
        $this->assertSame([['PartNumber' => 1, 'ETag' => '"e1"'], ['PartNumber' => 2, 'ETag' => '"e2"']], $complete['MultipartUpload']['Parts']);
    }

    public function test_head_returns_checksum_or_null_when_missing(): void
    {
        $this->mock->append(new Result(['ContentLength' => 3, 'ContentType' => 'image/jpeg', 'ETag' => '"x"', 'ChecksumSHA256' => 'abc=']));
        $this->assertSame(['size' => 3, 'content_type' => 'image/jpeg', 'etag' => 'x', 'sha256_base64' => 'abc='], $this->r2->head('k'));
        $this->assertSame('ENABLED', end($this->commands)['ChecksumMode']);

        $this->mock->append(function (CommandInterface $cmd) {
            return new S3Exception('Not Found', $cmd, ['code' => 'NotFound', 'response' => new Response(404)]);
        });
        $this->assertNull($this->r2->head('missing'));
    }

    public function test_unconfigured_adapter_explains_what_is_missing(): void
    {
        $this->expectExceptionMessage('R2 is not configured');
        (new R2ObjectStorage(['bucket' => 'b', 'key' => null, 'secret' => null, 'endpoint' => null, 'region' => 'auto', 'use_path_style' => true]))->head('x');
    }
}
