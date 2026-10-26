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

/** Staff-side downloads: whole-album zip, selection zip, single-file streaming, and the locks around them. */
class StaffDownloadTest extends TestCase
{
    use RefreshDatabase, UsesFakeStorage;

    private User $staff;

    private Album $album;

    /** @var array<string, Asset> */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeStorage();
        $this->staff = User::factory()->role('multimedia')->create();
        $this->album = Album::query()->create(['title' => 'Graduation Ceremony', 'created_by' => $this->staff->id]);

        foreach (['one', 'two'] as $name) {
            $asset = Asset::factory()->create(['title' => 'Shot '.$name, 'original_filename' => "{$name}.jpg"]);
            $this->album->assets()->attach($asset->id, ['position' => count($this->a) + 1]);
            $this->r2->simulatePut($asset->storage_key, "ORIG-{$name}", 'image/jpeg');
            $this->a[$name] = $asset;
        }
        // Bytes that never made it to storage cannot be downloaded.
        $missing = Asset::factory()->create(['title' => 'Lost', 'original_filename' => 'lost.jpg', 'storage_status' => 'missing']);
        $this->album->assets()->attach($missing->id, ['position' => 3]);
        $this->a['missing'] = $missing;
    }

    /** @return array<string, string> zip entry name => content */
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

    public function test_downloading_an_album_streams_one_zip_of_stored_originals_and_audits_each_asset(): void
    {
        $r = $this->actingAs($this->staff)->postJson("/api/albums/{$this->album->uuid}/download");

        $r->assertOk();
        $this->assertSame('application/zip', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="graduation-ceremony-', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertSame([
            'one.jpg' => 'ORIG-one',
            'two.jpg' => 'ORIG-two',
        ], $this->zipEntries($r->streamedContent()), 'the asset with missing storage is skipped');

        $downloads = AuditLog::query()->where('action', 'asset.downloaded')->get();
        $this->assertCount(2, $downloads);
        $this->assertTrue($downloads->every(fn ($l) => $l->actor_id === $this->staff->id && $l->properties['zip'] === true && $l->properties['variant'] === 'original'));
        $this->assertTrue($downloads->every(fn ($l) => $l->properties['album'] === $this->album->uuid));
        $this->assertEqualsCanonicalizing([$this->a['one']->id, $this->a['two']->id], $downloads->pluck('subject_id')->all());
    }

    public function test_downloading_a_selection_streams_exactly_the_stored_assets_that_were_asked_for(): void
    {
        $r = $this->actingAs($this->staff)->postJson('/api/assets/download', ['items' => [
            $this->a['two']->uuid,
            $this->a['missing']->uuid,
            'not-a-real-uuid',
        ]]);

        $r->assertOk();
        $this->assertSame('application/zip', $r->headers->get('Content-Type'));
        $this->assertSame(['two.jpg' => 'ORIG-two'], $this->zipEntries($r->streamedContent()));
        $this->assertTrue(AuditLog::query()->where('action', 'asset.downloaded')->where('subject_id', $this->a['two']->id)->exists());
        $this->assertSame(1, AuditLog::query()->where('action', 'asset.downloaded')->count());
    }

    public function test_the_selection_zip_keeps_the_order_the_browser_sent(): void
    {
        $r = $this->actingAs($this->staff)->postJson('/api/assets/download', ['items' => [
            $this->a['two']->uuid,
            $this->a['one']->uuid,
            $this->a['two']->uuid,
        ]]);

        $r->assertOk();
        $this->assertSame([
            'two.jpg' => 'ORIG-two',
            'one.jpg' => 'ORIG-one',
        ], $this->zipEntries($r->streamedContent()));
    }

    public function test_the_selection_zip_validates_the_batch_and_404s_when_nothing_can_be_sent(): void
    {
        $this->actingAs($this->staff)->postJson('/api/assets/download', [])->assertUnprocessable();
        $this->actingAs($this->staff)->postJson('/api/assets/download', ['items' => []])->assertUnprocessable();
        $this->actingAs($this->staff)->postJson('/api/assets/download', ['items' => array_fill(0, 501, 'x')])->assertUnprocessable();
        $this->actingAs($this->staff)->postJson('/api/assets/download', ['items' => [$this->a['missing']->uuid, 'not-a-real-uuid']])->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('action', 'asset.downloaded')->count());
    }

    public function test_the_file_endpoint_streams_one_original_for_phones_and_audits_it(): void
    {
        $r = $this->actingAs($this->staff)->getJson("/api/assets/{$this->a['one']->uuid}/file");

        $r->assertOk();
        $this->assertSame('ORIG-one', $r->streamedContent());
        $this->assertSame('image/jpeg', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="one.jpg"', (string) $r->headers->get('Content-Disposition'));
        $this->assertSame('no-store, private', $r->headers->get('Cache-Control'));

        $download = AuditLog::query()->where('action', 'asset.downloaded')->sole();
        $this->assertSame($this->a['one']->id, $download->subject_id);
        $this->assertTrue($download->properties['stream']);
        $this->assertSame($this->staff->id, $download->actor_id);

        $this->actingAs($this->staff)->getJson("/api/assets/{$this->a['missing']->uuid}/file")->assertNotFound();
    }

    public function test_downloads_need_a_session_and_stay_locked_until_the_temporary_password_is_replaced(): void
    {
        $this->postJson("/api/albums/{$this->album->uuid}/download")->assertUnauthorized();
        $this->getJson("/api/assets/{$this->a['one']->uuid}/file")->assertUnauthorized();
        $this->postJson('/api/assets/download', ['items' => [$this->a['one']->uuid]])->assertUnauthorized();

        $pending = User::factory()->create(['must_change_password' => true]);
        $r = $this->actingAs($pending)->postJson("/api/albums/{$this->album->uuid}/download");
        $r->assertForbidden()->assertJsonPath('code', 'must_change_password');
        $this->actingAs($pending)->getJson("/api/assets/{$this->a['one']->uuid}/file")
            ->assertForbidden()->assertJsonPath('message', 'You must set a new password before continuing.');

        $this->actingAs($pending)->postJson('/api/auth/change-password', ['password' => 'my-own-Password1', 'password_confirmation' => 'my-own-Password1'])->assertOk();
        $this->actingAs($pending->refresh())->postJson("/api/albums/{$this->album->uuid}/download")->assertOk();
    }

    public function test_an_empty_album_has_nothing_to_download(): void
    {
        $empty = Album::query()->create(['title' => 'Empty', 'created_by' => $this->staff->id]);

        $this->actingAs($this->staff)->postJson("/api/albums/{$empty->uuid}/download")->assertNotFound()
            ->assertJsonPath('message', 'This album has no downloadable media yet.');
    }
}
