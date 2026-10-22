<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStorage;
use Tests\TestCase;
use ZipArchive;

class StaffDownloadTest extends TestCase
{
    use RefreshDatabase, UsesFakeStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeStorage();
    }

    public function test_selected_originals_and_a_whole_album_download_as_a_zip(): void
    {
        $user = User::factory()->role('management')->create();
        $first = Asset::factory()->create(['original_filename' => 'one.jpg']);
        $second = Asset::factory()->create(['original_filename' => 'two.jpg']);
        $leftOut = Asset::factory()->create(['original_filename' => 'skip.jpg']);
        $this->r2->simulatePut($first->storage_key, 'ONE', 'image/jpeg');
        $this->r2->simulatePut($second->storage_key, 'TWO', 'image/jpeg');
        $this->r2->simulatePut($leftOut->storage_key, 'SKIP', 'image/jpeg');

        $selected = $this->actingAs($user)->post('/api/downloads', ['items' => [$second->uuid, $first->uuid]]);
        $selected->assertOk()->assertHeader('content-type', 'application/zip');
        $this->assertSame(['two.jpg' => 'TWO', 'one.jpg' => 'ONE'], $this->zipEntries($selected->streamedContent()));

        $album = Album::query()->create(['title' => 'Graduation', 'uuid' => (string) str()->uuid(), 'created_by' => $user->id]);
        $album->assets()->attach($first->id, ['position' => 1, 'added_by' => $user->id]);
        $album->assets()->attach($second->id, ['position' => 0, 'added_by' => $user->id]);

        $whole = $this->actingAs($user)->post('/api/downloads', ['album' => $album->uuid]);
        $whole->assertOk();
        $this->assertSame(['two.jpg', 'one.jpg'], array_keys($this->zipEntries($whole->streamedContent())));
        $this->assertSame(4, AuditLog::where('action', 'asset.downloaded')->count());

        $this->actingAs(User::factory()->role('multimedia')->create())->postJson('/api/downloads', [])->assertStatus(422);
    }

    public function test_guests_cannot_download(): void
    {
        $this->postJson('/api/downloads', ['items' => ['x']])->assertUnauthorized();
    }

    /** @return array<string, string> */
    private function zipEntries(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $entries;
    }
}
