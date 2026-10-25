<?php

namespace App\Storage;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use RuntimeException;

/** Cloudflare R2 through the S3 API (also works against MinIO for local end-to-end tests). */
class R2ObjectStorage implements ObjectStorage
{
    private ?S3Client $client = null;

    public function __construct(private array $config, ?S3Client $client = null)
    {
        $this->client = $client;
    }

    public function client(): S3Client
    {
        if ($this->client) {
            return $this->client;
        }
        if (blank($this->config['key']) || blank($this->config['secret']) || blank($this->config['endpoint'])) {
            throw new RuntimeException('R2 is not configured: set R2_ENDPOINT, R2_ACCESS_KEY_ID and R2_SECRET_ACCESS_KEY.');
        }

        return $this->client = new S3Client([
            'version' => 'latest',
            'region' => $this->config['region'] ?: 'auto',
            'endpoint' => $this->config['endpoint'],
            'use_path_style_endpoint' => (bool) $this->config['use_path_style'],
            'credentials' => ['key' => $this->config['key'], 'secret' => $this->config['secret']],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }

    private function bucket(): string
    {
        return $this->config['bucket'];
    }

    public function presignPut(string $key, string $contentType, int $size, int $ttl, ?string $sha256Base64 = null): array
    {
        $args = ['Bucket' => $this->bucket(), 'Key' => $key, 'ContentType' => $contentType, 'ContentLength' => $size];
        $headers = ['Content-Type' => $contentType];
        if ($sha256Base64) {
            // Signed into the URL: storage rejects the PUT if the bytes do not match.
            $args['ChecksumSHA256'] = $sha256Base64;
            $headers['x-amz-checksum-sha256'] = $sha256Base64;
        }
        $cmd = $this->client()->getCommand('PutObject', $args);
        $url = (string) $this->client()->createPresignedRequest($cmd, "+{$ttl} seconds")->getUri();

        return ['url' => $url, 'headers' => $headers];
    }

    public function put(string $key, string $contents, string $contentType): void
    {
        $this->client()->putObject([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'Body' => $contents,
            'ContentType' => $contentType,
        ]);
    }

    public function createMultipart(string $key, string $contentType): string
    {
        return (string) $this->client()->createMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $key, 'ContentType' => $contentType])['UploadId'];
    }

    public function presignPart(string $key, string $uploadId, int $partNumber, int $ttl): string
    {
        $cmd = $this->client()->getCommand('UploadPart', ['Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId, 'PartNumber' => $partNumber]);

        return (string) $this->client()->createPresignedRequest($cmd, "+{$ttl} seconds")->getUri();
    }

    public function listParts(string $key, string $uploadId): array
    {
        $parts = [];
        $marker = null;
        do {
            $res = $this->client()->listParts(array_filter(['Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId, 'PartNumberMarker' => $marker]));
            foreach ($res['Parts'] ?? [] as $p) {
                $parts[] = ['part_number' => (int) $p['PartNumber'], 'etag' => trim((string) $p['ETag'], '"'), 'size' => (int) $p['Size']];
            }
            $marker = $res['NextPartNumberMarker'] ?? null;
        } while (! empty($res['IsTruncated']));

        return $parts;
    }

    public function completeMultipart(string $key, string $uploadId, array $parts): void
    {
        usort($parts, fn ($a, $b) => $a['part_number'] <=> $b['part_number']);
        $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(fn ($p) => ['PartNumber' => $p['part_number'], 'ETag' => '"'.trim($p['etag'], '"').'"'], $parts)],
        ]);
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        try {
            $this->client()->abortMultipartUpload(['Bucket' => $this->bucket(), 'Key' => $key, 'UploadId' => $uploadId]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }

    public function head(string $key): ?array
    {
        try {
            $res = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $key, 'ChecksumMode' => 'ENABLED']);
        } catch (S3Exception $e) {
            if (in_array($e->getStatusCode(), [403, 404], true)) {
                return null;
            }
            throw $e;
        }

        return ['size' => (int) $res['ContentLength'], 'content_type' => $res['ContentType'] ?? null, 'etag' => isset($res['ETag']) ? trim($res['ETag'], '"') : null, 'sha256_base64' => $res['ChecksumSHA256'] ?? null];
    }

    public function presignGet(string $key, int $ttl, ?string $downloadName = null, bool $inline = true): string
    {
        $args = ['Bucket' => $this->bucket(), 'Key' => $key];
        if ($downloadName) {
            $safe = str_replace(['"', '\\', "\r", "\n"], '', $downloadName);
            $args['ResponseContentDisposition'] = ($inline ? 'inline' : 'attachment').'; filename="'.$safe.'"';
        }
        $cmd = $this->client()->getCommand('GetObject', $args);

        return (string) $this->client()->createPresignedRequest($cmd, "+{$ttl} seconds")->getUri();
    }

    public function readChunks(string $key, int $chunkSize = 8388608): iterable
    {
        $body = $this->client()->getObject(['Bucket' => $this->bucket(), 'Key' => $key])['Body'];
        while (! $body->eof()) {
            yield $body->read($chunkSize);
        }
    }

    public function delete(string $key): void
    {
        $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $key]);
    }

    public function usage(array $prefixes = ['originals/', 'derivatives/']): array
    {
        $out = ['objects' => 0, 'bytes' => 0, 'by_prefix' => []];
        foreach ($prefixes as $prefix) {
            $o = 0;
            $b = 0;
            foreach ($this->client()->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket(), 'Prefix' => $prefix]) as $page) {
                foreach ($page['Contents'] ?? [] as $obj) {
                    $o++;
                    $b += (int) $obj['Size'];
                }
            }
            $out['by_prefix'][$prefix] = ['objects' => $o, 'bytes' => $b];
            $out['objects'] += $o;
            $out['bytes'] += $b;
        }

        return $out;
    }
}
