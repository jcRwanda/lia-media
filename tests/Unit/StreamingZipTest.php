<?php

namespace Tests\Unit;

use App\Support\StreamingZip;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/** The streaming zip writer, verified by reading the output back with PHP's own ZipArchive. */
class StreamingZipTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'zip-test');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** @param array<string, string|list<string>> $entries */
    private function build(array $entries, ?int $maxEntryBytes = null, ?int $maxArchiveBytes = null): void
    {
        $out = fopen($this->path, 'wb');
        $zip = new StreamingZip($out, $maxEntryBytes, $maxArchiveBytes);
        foreach ($entries as $name => $chunks) {
            $zip->add($name, is_array($chunks) ? $chunks : [$chunks]);
        }
        $zip->finish();
        fclose($out);
    }

    /** @return list<string> */
    private function entryNames(): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $names;
    }

    public function test_writes_entries_whose_contents_survive_chunked_streaming(): void
    {
        $this->build(['photo.jpg' => ['hel', 'lo ', 'wor', 'ld'], 'clip.webp' => 'second']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->path));
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame('hello world', $zip->getFromName('photo.jpg'));
        $this->assertSame('second', $zip->getFromName('clip.webp'));
        $zip->close();
    }

    public function test_flattens_path_separators_so_entries_cannot_escape_the_archive(): void
    {
        $this->build(['../../etc/passwd' => 'x']);

        $this->assertSame(['-..-etc-passwd'], $this->entryNames());
    }

    public function test_renames_duplicate_entry_names_instead_of_overwriting(): void
    {
        $out = fopen($this->path, 'wb');
        $zip = new StreamingZip($out);
        $zip->add('photo.jpg', ['one']);
        $zip->add('photo.jpg', ['two']);
        $zip->add('photo-2.jpg', ['three']);
        $zip->finish();
        fclose($out);

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($this->path));
        $this->assertSame(['photo.jpg', 'photo-2.jpg', 'photo-2-2.jpg'], $this->entryNames());
        $this->assertSame('one', $archive->getFromName('photo.jpg'));
        $this->assertSame('two', $archive->getFromName('photo-2.jpg'));
        $this->assertSame('three', $archive->getFromName('photo-2-2.jpg'));
        $archive->close();
    }

    public function test_rejects_an_entry_larger_than_the_configured_limit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('classic zip');

        $this->build(['big.bin' => ['123456']], 5);
    }

    public function test_writes_a_readable_empty_archive_when_nothing_is_added(): void
    {
        $this->build([]);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->path));
        $this->assertSame(0, $zip->numFiles);
        $zip->close();
    }
}
