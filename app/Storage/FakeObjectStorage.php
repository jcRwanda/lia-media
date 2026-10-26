<?php

namespace App\Storage;

use Illuminate\Support\Str;

/**
 * In-memory stand-in for R2 used by the test-suite (and offline UI work). Behaves like the S3 API
 * for the calls the archive makes, including checksum rejection on PUT.
 */
class FakeObjectStorage implements ObjectStorage
{
    /** @var array<string, array{body: string, content_type: string, sha256_base64: ?string}> */
    public array $objects = [];

    /** @var array<string, array{key: string, content_type: string, parts: array<int, string>}> */
    public array $multipart = [];

    public array $calls = [];

    public function presignPut(string $key, string $contentType, int $size, int $ttl, ?string $sha256Base64 = null): array
    {
        $this->calls[] = ['presignPut', $key];
        $headers = ['Content-Type' => $contentType] + ($sha256Base64 ? ['x-amz-checksum-sha256' => $sha256Base64] : []);

        return ['url' => "https://fake-r2.test/{$key}?X-Amz-Expires={$ttl}", 'headers' => $headers];
    }

    public function put(string $key, string $contents, string $contentType): void
    {
        $this->calls[] = ['put', $key];
        $this->objects[$key] = ['body' => $contents, 'content_type' => $contentType, 'sha256_base64' => base64_encode(hash('sha256', $contents, true))];
    }

    /** Simulate the browser PUT. Returns false when the signed checksum does not match (S3 BadDigest). */
    public function simulatePut(string $key, string $body, string $contentType, ?string $sha256Base64 = null): bool
    {
        if ($sha256Base64 && base64_encode(hash('sha256', $body, true)) !== $sha256Base64) {
            return false;
        }
        $this->objects[$key] = ['body' => $body, 'content_type' => $contentType, 'sha256_base64' => $sha256Base64];

        return true;
    }

    public function createMultipart(string $key, string $contentType): string
    {
        $id = 'upl-'.Str::random(12);
        $this->multipart[$id] = ['key' => $key, 'content_type' => $contentType, 'parts' => []];

        return $id;
    }

    public function presignPart(string $key, string $uploadId, int $partNumber, int $ttl): string
    {
        return "https://fake-r2.test/{$key}?uploadId={$uploadId}&partNumber={$partNumber}&X-Amz-Expires={$ttl}";
    }

    public function simulatePart(string $uploadId, int $partNumber, string $body): string
    {
        $this->multipart[$uploadId]['parts'][$partNumber] = $body;

        return md5($body);
    }

    public function listParts(string $key, string $uploadId): array
    {
        $parts = [];
        foreach ($this->multipart[$uploadId]['parts'] ?? [] as $n => $body) {
            $parts[] = ['part_number' => $n, 'etag' => md5($body), 'size' => strlen($body)];
        }
        usort($parts, fn ($a, $b) => $a['part_number'] <=> $b['part_number']);

        return $parts;
    }

    public function completeMultipart(string $key, string $uploadId, array $parts): void
    {
        $up = $this->multipart[$uploadId] ?? throw new \RuntimeException('NoSuchUpload');
        usort($parts, fn ($a, $b) => $a['part_number'] <=> $b['part_number']);
        $body = '';
        foreach ($parts as $p) {
            $chunk = $up['parts'][$p['part_number']] ?? throw new \RuntimeException('InvalidPart');
            if (md5($chunk) !== trim($p['etag'], '"')) {
                throw new \RuntimeException('InvalidPart');
            }
            $body .= $chunk;
        }
        $this->objects[$key] = ['body' => $body, 'content_type' => $up['content_type'], 'sha256_base64' => null];
        unset($this->multipart[$uploadId]);
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        $this->calls[] = ['abort', $uploadId];
        unset($this->multipart[$uploadId]);
    }

    public function head(string $key): ?array
    {
        $o = $this->objects[$key] ?? null;

        return $o ? ['size' => strlen($o['body']), 'content_type' => $o['content_type'], 'etag' => md5($o['body']), 'sha256_base64' => $o['sha256_base64']] : null;
    }

    public function presignGet(string $key, int $ttl, ?string $downloadName = null, bool $inline = true): string
    {
        return "https://fake-r2.test/{$key}?get=1&X-Amz-Expires={$ttl}".($downloadName ? '&disposition='.($inline ? 'inline' : 'attachment') : '');
    }

    public function readChunks(string $key, int $chunkSize = 8388608): iterable
    {
        $body = $this->objects[$key]['body'] ?? '';
        foreach (str_split($body, max(1, $chunkSize)) as $c) {
            yield $c;
        }
    }

    public function delete(string $key): void
    {
        $this->calls[] = ['delete', $key];
        unset($this->objects[$key]);
    }

    public function putFile(string $key, string $contentType, string $body): void
    {
        $this->calls[] = ['putFile', $key];
        $this->objects[$key] = ['body' => $body, 'content_type' => $contentType, 'sha256_base64' => null];
    }

    public function usage(array $prefixes = ['originals/', 'derivatives/']): array
    {
        $out = ['objects' => 0, 'bytes' => 0, 'by_prefix' => []];
        foreach ($prefixes as $p) {
            $o = 0;
            $b = 0;
            foreach ($this->objects as $k => $obj) {
                if (str_starts_with($k, $p)) {
                    $o++;
                    $b += strlen($obj['body']);
                }
            }
            $out['by_prefix'][$p] = ['objects' => $o, 'bytes' => $b];
            $out['objects'] += $o;
            $out['bytes'] += $b;
        }

        return $out;
    }
}
