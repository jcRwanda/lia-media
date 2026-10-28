<?php

namespace App\Support;

use App\Storage\ObjectStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * Re-encodes uploaded photos as JPEG (max 2000px on the longest side) using
 * Imagick or GD when available. Falls back to storing the original file.
 */
class ImageNormalizer
{
    public const MAX_EDGE = 2000;

    public const QUALITY = 82;

    /**
     * Store an uploaded photo in the private object storage (R2), re-encoded as JPEG when possible.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public static function store(UploadedFile $file, string $dir, ?ObjectStorage $storage = null): array
    {
        $storage ??= app(ObjectStorage::class);
        $dir = trim($dir, '/');
        $original = $file->getClientOriginalName() ?: 'photo';
        $jpeg = self::toJpeg($file->getRealPath());

        if ($jpeg !== null) {
            $path = $dir.'/'.Str::random(40).'.jpg';
            $storage->put($path, $jpeg, 'image/jpeg');
            $base = pathinfo($original, PATHINFO_FILENAME) ?: 'photo';

            return ['path' => $path, 'name' => $base.'.jpg', 'mime' => 'image/jpeg'];
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = $dir.'/'.Str::random(40).'.'.$ext;
        $storage->put($path, (string) file_get_contents($file->getRealPath()), $mime);

        return ['path' => $path, 'name' => $original, 'mime' => $mime];
    }

    public static function toJpeg(string|false $path): ?string
    {
        if (! $path || ! is_file($path)) {
            return null;
        }

        if (extension_loaded('imagick')) {
            try {
                $img = new \Imagick($path);
                if (method_exists($img, 'autoOrient')) {
                    $img->autoOrient();
                }
                $img->setImageBackgroundColor('white');
                $img = $img->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $w = $img->getImageWidth();
                $h = $img->getImageHeight();
                if (max($w, $h) > self::MAX_EDGE) {
                    $img->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, true);
                }
                $img->setImageFormat('jpeg');
                $img->setImageCompressionQuality(self::QUALITY);
                $img->stripImage();
                $blob = $img->getImageBlob();
                $img->clear();

                return $blob ?: null;
            } catch (Throwable) {
                // fall through to GD
            }
        }

        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $raw = @file_get_contents($path);
            $src = $raw === false ? false : @imagecreatefromstring($raw);
            if (! $src) {
                return null;
            }
            $src = self::orientGd($src, $path);
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, self::MAX_EDGE / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagejpeg($dst, null, self::QUALITY);
            $blob = ob_get_clean();
            imagedestroy($src);
            imagedestroy($dst);

            return $blob ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function orientGd(\GdImage $img, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ((int) $o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };

        return $rotated ?: $img;
    }
}
