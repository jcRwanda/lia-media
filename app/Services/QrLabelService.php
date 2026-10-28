<?php

namespace App\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Builds branded QR codes (ECC level H, LIA logo in the centre) as SVG without GD.
 */
class QrLabelService
{
    public function svg(string $data, bool $withLogo = true, string $color = '#111827'): string
    {
        $options = new QROptions([
            'eccLevel' => EccLevel::H,
            'addQuietzone' => false,
        ]);

        $matrix = (new QRCode($options))->addByteSegment($data)->getQRMatrix();
        $size = $matrix->getSize();

        if ($withLogo) {
            $space = (int) round($size * 0.22);
            if ($space % 2 !== $size % 2) {
                $space++;
            }
            $matrix->setLogoSpace($space, $space);
        }

        $q = 2;
        $total = $size + $q * 2;
        $px = $total * 8;
        $path = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->check($x, $y)) {
                    $path .= 'M'.($x + $q).' '.($y + $q).'h1v1h-1z';
                }
            }
        }

        $logo = '';
        if ($withLogo && isset($space)) {
            $file = public_path('images/lia-logo-white.jpg');
            if (is_file($file)) {
                $b64 = base64_encode((string) file_get_contents($file));
                $pad = 0.6;
                $start = ($size - $space) / 2 + $q + $pad;
                $w = $space - $pad * 2;
                $logo = '<image x="'.$start.'" y="'.$start.'" width="'.$w.'" height="'.$w.'" xlink:href="data:image/jpeg;base64,'.$b64.'"/>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 '.$total.' '.$total.'" width="'.$px.'" height="'.$px.'" shape-rendering="crispEdges">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#ffffff"/>'
            .'<path fill="'.$color.'" d="'.$path.'"/>'
            .$logo
            .'</svg>';
    }

    /**
     * Pure-PHP PNG (no GD) of the QR with a blank centre logo area; the logo is overlaid in HTML.
     */
    public function png(string $data, int $scale = 8): string
    {
        $options = new QROptions(['eccLevel' => EccLevel::H, 'addQuietzone' => false]);
        $matrix = (new QRCode($options))->addByteSegment($data)->getQRMatrix();
        $size = $matrix->getSize();
        $space = (int) round($size * 0.22);
        if ($space % 2 !== $size % 2) {
            $space++;
        }
        $matrix->setLogoSpace($space, $space);
        $q = 2;
        $dim = ($size + $q * 2) * $scale;
        $raw = '';
        for ($y = 0; $y < $size + $q * 2; $y++) {
            $row = '';
            for ($x = 0; $x < $size + $q * 2; $x++) {
                $mx = $x - $q;
                $my = $y - $q;
                $dark = $mx >= 0 && $my >= 0 && $mx < $size && $my < $size && $matrix->check($mx, $my);
                $row .= str_repeat($dark ? "\x00" : "\xff", $scale);
            }
            $raw .= str_repeat("\x00".$row, $scale);
        }
        $chunk = fn (string $t, string $d) => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $dim, $dim, 8, 0, 0, 0, 0))
            .$chunk('IDAT', gzcompress($raw, 9))
            .$chunk('IEND', '');
    }

    /**
     * QR as absolutely positioned HTML blocks (one per horizontal run of dark modules).
     * dompdf renders these as vector rectangles, so no GD / image extension is needed.
     */
    public function html(string $data, float $sizeMm = 34.0): string
    {
        $options = new QROptions(['eccLevel' => EccLevel::H, 'addQuietzone' => false]);
        $matrix = (new QRCode($options))->addByteSegment($data)->getQRMatrix();
        $size = $matrix->getSize();
        $space = (int) round($size * 0.22);
        if ($space % 2 !== $size % 2) {
            $space++;
        }
        $matrix->setLogoSpace($space, $space);
        $q = 2;
        $m = $sizeMm / ($size + $q * 2);
        $out = '';
        for ($y = 0; $y < $size; $y++) {
            $x = 0;
            while ($x < $size) {
                if (! $matrix->check($x, $y)) {
                    $x++;
                    continue;
                }
                $start = $x;
                while ($x < $size && $matrix->check($x, $y)) {
                    $x++;
                }
                $out .= sprintf('<div style="position:absolute;left:%.3Fmm;top:%.3Fmm;width:%.3Fmm;height:%.3Fmm;background:#111827"></div>',
                    ($start + $q) * $m, ($y + $q) * $m, ($x - $start) * $m + 0.02, $m + 0.02);
            }
        }

        return $out;
    }

    public function pngDataUri(string $data): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($data));
    }

    public function dataUri(string $data, bool $withLogo = true, string $color = '#111827'): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($data, $withLogo, $color));
    }
}
