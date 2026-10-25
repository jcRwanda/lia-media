<?php

namespace App\Storage;

/**
 * Private object storage used by the archive (Cloudflare R2 in production).
 * The browser never sees credentials: it only receives short-lived presigned URLs.
 */
interface ObjectStorage
{
    /** @return array{url: string, headers: array<string, string>} */
    public function presignPut(string $key, string $contentType, int $size, int $ttl, ?string $sha256Base64 = null): array;

    /** Store a small server-side object (signatures, ID documents) privately. */
    public function put(string $key, string $contents, string $contentType): void;

    public function createMultipart(string $key, string $contentType): string;

    public function presignPart(string $key, string $uploadId, int $partNumber, int $ttl): string;

    /** @return list<array{part_number: int, etag: string, size: int}> */
    public function listParts(string $key, string $uploadId): array;

    /** @param list<array{part_number: int, etag: string}> $parts */
    public function completeMultipart(string $key, string $uploadId, array $parts): void;

    public function abortMultipart(string $key, string $uploadId): void;

    /** @return array{size: int, content_type: ?string, etag: ?string, sha256_base64: ?string}|null */
    public function head(string $key): ?array;

    public function presignGet(string $key, int $ttl, ?string $downloadName = null, bool $inline = true): string;

    /** Stream the object in chunks (for server-side checksum verification). @return iterable<string> */
    public function readChunks(string $key, int $chunkSize = 8388608): iterable;

    public function delete(string $key): void;

    /** @return array{objects: int, bytes: int, by_prefix: array<string, array{objects: int, bytes: int}>} */
    public function usage(array $prefixes = ['originals/', 'derivatives/']): array;
}
