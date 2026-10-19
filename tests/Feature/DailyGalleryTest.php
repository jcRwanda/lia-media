<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Asset;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStorage;
use Tests\TestCase;

class DailyGalleryTest extends TestCase
{
    use RefreshDatabase, UsesFakeStorage;

    private User $mm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeStorage();
        $this->mm = User::factory()->role('multimedia')->create();
    }

    /** @return array{uuid: string, title: string} */
    private function upload(string $body, array $context = []): array
    {
        $init = $this->actingAs($this->mm)->postJson('/api/uploads', [
            'filename' => 'photo-'.substr(hash('sha256', $body), 0, 6).'.jpg', 'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($body), 'sha256' => hash('sha256', $body), 'context' => $context,
        ])->assertCreated()->json();
        $this->r2->simulatePut($this->keyOf($init['upload']['uuid']), $body, 'image/jpeg', $init['put']['headers']['x-amz-checksum-sha256']);

        return $this->actingAs($this->mm)->postJson("/api/uploads/{$init['upload']['uuid']}/complete")->assertOk()->json('asset');
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Africa/Kigali')->startOfDay();
    }

    public function test_upload_applies_defaults_default_album_and_day_gallery(): void
    {
        $asset = $this->upload(random_bytes(256));
        $a = Asset::where('uuid', $asset['uuid'])->firstOrFail();

        $this->assertSame('unreviewed', $a->review_state);
        $this->assertNotNull($a->captured_at);

        $default = Album::query()->where('is_default', true)->firstOrFail();
        $this->assertFalse($default->isDaily());
        $this->assertTrue($default->assets()->whereKey($a->id)->exists());

        $gallery = Album::query()->daily()->firstOrFail();
        $this->assertSame($this->today()->toDateString(), $gallery->day);
        $this->assertSame($this->today()->format('l, j M Y'), $gallery->title);
        $this->assertTrue($gallery->assets()->whereKey($a->id)->exists());

        // A second upload with configured defaults gets the review state and tags from settings.
        app(SettingsService::class)->put(['default_review_state' => 'approved', 'default_tags' => 'gala, stage']);
        $second = $this->upload(random_bytes(512));
        $b = Asset::where('uuid', $second['uuid'])->firstOrFail();
        $this->assertSame('approved', $b->review_state);
        $this->assertSame(['gala', 'stage'], $b->tags->pluck('slug')->sort()->values()->all());

        // Both day galleries reused the same album (same day) and it holds both assets.
        $this->assertSame(1, Album::query()->daily()->count());
        $this->assertSame(2, Album::query()->daily()->first()->assets()->count());
    }

    public function test_explicit_album_is_respected_but_asset_still_filed_in_day_gallery(): void
    {
        $album = Album::query()->create(['title' => 'Picks', 'created_by' => $this->mm->id]);
        $asset = $this->upload(random_bytes(128), ['album_id' => $album->id]);
        $a = Asset::where('uuid', $asset['uuid'])->firstOrFail();

        $this->assertTrue($album->assets()->whereKey($a->id)->exists());
        $gallery = Album::query()->daily()->firstOrFail();
        $this->assertTrue($gallery->assets()->whereKey($a->id)->exists());
        $this->assertTrue(Album::query()->where('is_default', true)->exists(), 'a default album always exists');
        $this->assertFalse(Album::query()->where('is_default', true)->first()->assets()->whereKey($a->id)->exists());
    }

    public function test_daily_galleries_can_be_disabled(): void
    {
        app(SettingsService::class)->put(['daily_galleries_enabled' => false]);
        $asset = $this->upload(random_bytes(96));
        $a = Asset::where('uuid', $asset['uuid'])->firstOrFail();

        $this->assertSame(0, Album::query()->daily()->count());
        $this->assertTrue(Album::query()->where('is_default', true)->first()->assets()->whereKey($a->id)->exists());
    }

    public function test_backfill_groups_existing_assets_into_day_galleries_idempotently(): void
    {
        $day1 = $this->today();
        $day2 = $this->today()->subDay();
        Asset::factory()->create(['captured_at' => $day1->setTime(9, 0)]);
        Asset::factory()->create(['captured_at' => $day1->setTime(17, 30)]);
        Asset::factory()->create(['captured_at' => $day2->setTime(12, 0)]);
        $this->assertSame(0, Album::query()->daily()->count());

        $this->artisan('gallery:backfill')->assertSuccessful();

        $this->assertSame(2, Album::query()->daily()->count());
        $dayOne = Album::query()->where('day', $day1->toDateString())->firstOrFail();
        $this->assertSame($day1->format('l, j M Y'), $dayOne->title);
        $this->assertSame(2, $dayOne->assets()->count());
        $this->assertSame(1, Album::query()->where('day', $day2->toDateString())->first()->assets()->count());

        // Idempotent: a second pass attaches nothing and leaves the same two galleries.
        $this->artisan('gallery:backfill')->expectsOutputToContain('0 newly filed')->assertSuccessful();
        $this->assertSame(2, Album::query()->daily()->count());
        $this->assertSame(3, Album::query()->daily()->withCount('assets')->get()->sum('assets_count'));
    }

    public function test_album_index_lists_day_galleries_newest_first_and_flags_today(): void
    {
        $galleries = app(\App\Services\DailyGalleryService::class);
        $galleries->forDay($this->today()->subDays(2));
        $galleries->forDay($this->today());
        $galleries->forDay($this->today()->subDay());
        Album::query()->create(['title' => 'Curated', 'created_by' => $this->mm->id]);

        $data = $this->actingAs($this->mm)->getJson('/api/albums')->assertOk()->json('data');

        $this->assertSame('daily', $data[0]['kind']);
        $this->assertSame($this->today()->format('l, j M Y'), $data[0]['title']);
        $this->assertTrue($data[0]['is_today']);
        $this->assertSame($this->today()->subDay()->format('l, j M Y'), $data[1]['title']);
        $this->assertFalse($data[1]['is_today']);
        $this->assertSame($this->today()->subDays(2)->format('l, j M Y'), $data[2]['title']);
        $this->assertSame('curated', $data[3]['kind']);
        $this->assertSame('Curated', $data[3]['title']);
    }

    public function test_auto_managed_albums_cannot_be_deleted(): void
    {
        $galleries = app(\App\Services\DailyGalleryService::class);
        $day = $galleries->forDay($this->today());
        $default = app(\App\Services\DefaultAlbumService::class)->ensure();

        $this->actingAs($this->mm)->deleteJson("/api/albums/{$day->uuid}")->assertStatus(422);
        $this->actingAs($this->mm)->deleteJson("/api/albums/{$default->uuid}")->assertStatus(422);
        $this->assertNotSoftDeleted($day);
    }
}
