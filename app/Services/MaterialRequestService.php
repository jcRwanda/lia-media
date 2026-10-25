<?php

namespace App\Services;

use App\Storage\ObjectStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MaterialRequestService
{
    public function __construct(private ObjectStorage $storage) {}

    private const ID_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/pdf' => 'pdf',
    ];

    /**
     * @return array{path: string, mime: string, name: string}
     */
    public function storeIdDocument(UploadedFile $file): array
    {
        $mime = strtolower((string) $file->getMimeType());
        if (! isset(self::ID_MIME_EXTENSIONS[$mime])) {
            throw ValidationException::withMessages(['id_document' => 'Upload an image (JPG, PNG, WEBP, HEIC) or a PDF.']);
        }
        if ($file->getSize() > config('material-requests.id_document_max_bytes')) {
            throw ValidationException::withMessages(['id_document' => 'The ID document is too large.']);
        }

        $key = 'material-requests/id-documents/'.Str::uuid().'.'.self::ID_MIME_EXTENSIONS[$mime];
        $this->storage->put($key, (string) file_get_contents($file->getRealPath()), $mime);

        return ['path' => $key, 'mime' => $mime, 'name' => $file->getClientOriginalName()];
    }

    /**
     * @return array{path: string, mime: string, name: string}
     */
    public function storeIdDocumentDataUri(string $dataUri): array
    {
        [$mime, $bytes] = $this->decodeDataUri($dataUri, 'id_document');
        if (! isset(self::ID_MIME_EXTENSIONS[$mime])) {
            throw ValidationException::withMessages(['id_document' => 'Upload an image (JPG, PNG, WEBP, HEIC) or a PDF.']);
        }
        if (strlen($bytes) > config('material-requests.id_document_max_bytes')) {
            throw ValidationException::withMessages(['id_document' => 'The ID document is too large.']);
        }

        $key = 'material-requests/id-documents/'.Str::uuid().'.'.self::ID_MIME_EXTENSIONS[$mime];
        $this->storage->put($key, $bytes, $mime);

        return ['path' => $key, 'mime' => $mime, 'name' => 'id-document.'.self::ID_MIME_EXTENSIONS[$mime]];
    }

    /**
     * @return array{path: string, mime: string, name: string}
     */
    public function storeSignature(UploadedFile|string $signature): array
    {
        if ($signature instanceof UploadedFile) {
            $mime = strtolower((string) $signature->getMimeType());
            $bytes = (string) file_get_contents($signature->getRealPath());
        } else {
            [$mime, $bytes] = $this->decodeDataUri($signature, 'signature');
        }

        if ($mime !== 'image/png') {
            throw ValidationException::withMessages(['signature' => 'The signature must be a PNG image.']);
        }
        if (strlen($bytes) > config('material-requests.signature_max_bytes')) {
            throw ValidationException::withMessages(['signature' => 'The signature image is too large.']);
        }

        $key = 'material-requests/signatures/'.Str::uuid().'.png';
        $this->storage->put($key, $bytes, 'image/png');

        return ['path' => $key, 'mime' => 'image/png', 'name' => 'signature.png'];
    }

    public function presign(?string $path, ?string $name = null): ?string
    {
        return $path ? $this->storage->presignGet($path, config('media.ttl.download'), $name, true) : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeDataUri(string $value, string $field): array
    {
        if (! preg_match('#^data:([a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+);base64,(.*)$#s', $value, $m)) {
            throw ValidationException::withMessages([$field => 'The uploaded file is not valid.']);
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false) {
            throw ValidationException::withMessages([$field => 'The uploaded file is not valid.']);
        }

        return [strtolower($m[1]), $bytes];
    }
}
