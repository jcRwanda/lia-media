<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal streaming ZIP writer for visitor downloads. Entries are stored uncompressed (media is
 * already compressed) and written straight to the output stream with data descriptors, so memory
 * use stays flat regardless of album size and nothing is buffered on disk. Stays inside the classic
 * 32-bit zip limits; callers guard against larger payloads.
 */
class StreamingZip
{
    public const DEFAULT_MAX_BYTES = 0xFFFFFFFF; // classic zip cannot represent larger sizes/offsets

    /** @var resource */
    private $out;

    private int $offset = 0;

    /** @var array<string, array{offset: int, crc: int, size: int}> */
    private array $entries = [];

    private array $usedNames = [];

    private int $maxEntryBytes;

    private int $maxArchiveBytes;

    /** @param resource $out */
    public function __construct($out, ?int $maxEntryBytes = null, ?int $maxArchiveBytes = null)
    {
        if (! is_resource($out)) {
            throw new RuntimeException('StreamingZip needs a writable stream.');
        }
        $this->out = $out;
        $this->maxEntryBytes = $maxEntryBytes ?? self::DEFAULT_MAX_BYTES;
        $this->maxArchiveBytes = $maxArchiveBytes ?? self::DEFAULT_MAX_BYTES;
    }

    /** @param iterable<string> $chunks */
    public function add(string $name, iterable $chunks): void
    {
        $name = $this->uniqueName($this->sanitize($name));
        $localOffset = $this->offset;
        $this->write($this->localHeader($name));
        $crc = hash_init('crc32b');
        $size = 0;
        foreach ($chunks as $chunk) {
            $size += strlen($chunk);
            if ($size > $this->maxEntryBytes || $this->offset + $size > $this->maxArchiveBytes) {
                throw new RuntimeException('Refusing to build a zip beyond classic zip size limits.');
            }
            hash_update($crc, $chunk);
            $this->write($chunk);
        }
        $crcValue = (int) hexdec(hash_final($crc));
        $this->write(pack('V', 0x08074B50).pack('V', $crcValue).pack('V', $size).pack('V', $size));
        $this->entries[$name] = ['offset' => $localOffset, 'crc' => $crcValue, 'size' => $size];
    }

    public function finish(): void
    {
        $cdStart = $this->offset;
        foreach ($this->entries as $name => $e) {
            $this->write($this->centralHeader($name, $e));
        }
        $count = count($this->entries);
        $this->write(
            pack('V', 0x06054B50).pack('v', 0).pack('v', 0).pack('v', $count).pack('v', $count)
            .pack('V', $this->offset - $cdStart).pack('V', $cdStart).pack('v', 0)
        );
    }

    private function localHeader(string $name): string
    {
        [$time, $date] = $this->dosTimestamp();

        return pack('V', 0x04034B50).pack('vvvvv', 20, 0x0008, 0, $time, $date)
            .pack('VVV', 0, 0, 0).pack('vv', strlen($name), 0).$name;
    }

    /** @param array{offset: int, crc: int, size: int} $e */
    private function centralHeader(string $name, array $e): string
    {
        [$time, $date] = $this->dosTimestamp();

        return pack('V', 0x02014B50).pack('vvvvv', 20, 20, 0x0008, 0, $time).pack('v', $date)
            .pack('VVV', $e['crc'], $e['size'], $e['size']).pack('vvv', strlen($name), 0, 0)
            .pack('vvV', 0, 0, 0).pack('V', $e['offset']).$name;
    }

    /** @return array{0: int, 1: int} DOS time and date packed as zip stores them. */
    private function dosTimestamp(): array
    {
        $time = ((int) date('H') << 11) | ((int) date('i') << 5) | ((int) date('s') >> 1);
        $date = (((int) date('Y') - 1980) << 9) | ((int) date('n') << 5) | (int) date('j');

        return [$time, $date];
    }

    private function sanitize(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = str_replace(['/', ':'], '-', $name);
        $name = trim($name, '. ');

        return $name !== '' ? $name : 'file';
    }

    private function uniqueName(string $name): string
    {
        if (! isset($this->usedNames[strtolower($name)])) {
            $this->usedNames[strtolower($name)] = true;

            return $name;
        }
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        for ($i = 2; ; $i++) {
            $candidate = $base.'-'.$i.($ext !== '' ? '.'.$ext : '');
            if (! isset($this->usedNames[strtolower($candidate)])) {
                $this->usedNames[strtolower($candidate)] = true;

                return $candidate;
            }
        }
    }

    private function write(string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($this->out, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed writing zip data.');
            }
            $bytes = substr($bytes, $written);
            $this->offset += $written;
        }
    }
}
