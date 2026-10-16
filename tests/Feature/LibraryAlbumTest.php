<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Asset;
use App\Models\AssetDerivative;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStorage;
use Tests\TestCase;

class LibraryAlbumTest extends TestCase
{
    use RefreshDatabase, UsesFakeStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeStorage();
    }

    public function test_library_filters_search_and_presigned_thumbs(): void
    {
        $u = User::factory()->role('management')->create();
        $ev = Event::query()->create(['title' => 'Graduation', 'slug' => 'grad']);
        $a = Asset::factory()->create(['title' => 'Red carpet arrival', 'event_id' => $ev->id]);
        $a->tags()->attach(Tag::query()->create(['name' => 'Stage', 'slug' => 'stage']));
        AssetDerivative::query()->create(['asset_id' => $a->id, 'type' => 'thumb', 'storage_key' => "derivatives/{$a->uuid}/thumb.webp", 'mime_type' => 'image/webp', 'status' => 'ready']);
        Asset::factory()->create(['title' => 'Backstage', 'kind' => 'video', 'mime_type' => 'video/mp4', 'review_state' => 'approved']);

        $this->actingAs($u)->getJson('/api/assets')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($u)->getJson('/api/assets?q=carpet')->assertJsonCount(1, 'data')->assertJsonPath('data.0.thumb_url', fn ($url) => str_contains($url, 'thumb.webp') && str_contains($url, 'X-Amz-Expires=300'));
        $this->actingAs($u)->getJson('/api/assets?q=stage')->assertJsonCount(2, 'data'); // tag + title "Backstage"
        $this->actingAs($u)->getJson('/api/assets?tag=stage')->assertJsonCount(1, 'data');
        $this->actingAs($u)->getJson('/api/assets?kind=video')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Backstage');
        $this->actingAs($u)->getJson('/api/assets?review_state=approved')->assertJsonCount(1, 'data');
        $this->actingAs($u)->getJson("/api/assets?event_id={$ev->id}")->assertJsonCount(1, 'data');
        $this->actingAs($u)->getJson("/api/assets/{$a->uuid}")->assertOk()->assertJsonPath('asset.tags.0', 'Stage')->assertJsonPath('asset.event.title', 'Graduation')->assertJsonMissingPath('asset.storage_key');
    }

    public function test_edit_permissions_download_audit_and_soft_delete(): void
    {
        $a = Asset::factory()->create();
        $mgmt = User::factory()->role('management')->create();
        $comms = User::factory()->role('communications')->create();
        $this->actingAs($mgmt)->patchJson("/api/assets/{$a->uuid}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($comms)->patchJson("/api/assets/{$a->uuid}", ['title' => 'Opening night', 'tags' => ['Gala', 'gala', 'Stage']])->assertOk()->assertJsonPath('asset.title', 'Opening night')->assertJsonCount(2, 'asset.tags');

        $url = $this->actingAs($mgmt)->postJson("/api/assets/{$a->uuid}/download")->assertOk()->json('url');
        $this->assertStringContainsString('disposition=attachment', $url);
        $this->assertTrue(AuditLog::where('action', 'asset.downloaded')->where('actor_id', $mgmt->id)->exists());

        $this->actingAs($comms)->deleteJson("/api/assets/{$a->uuid}")->assertForbidden();
        $this->actingAs(User::factory()->role('admin')->create())->deleteJson("/api/assets/{$a->uuid}")->assertOk();
        $this->assertSoftDeleted($a);
        $this->actingAs($mgmt)->getJson('/api/assets')->assertJsonCount(0, 'data');
    }

    public function test_albums_crud_order_cover_and_bulk_add(): void
    {
        $mm = User::factory()->role('multimedia')->create();
        [$a, $b, $c] = Asset::factory()->count(3)->create();
        $uuid = $this->actingAs($mm)->postJson('/api/albums', ['title' => 'Cohort 4 graduation'])->assertCreated()->json('album.uuid');
        $this->actingAs($mm)->postJson("/api/albums/{$uuid}/assets", ['uuids' => [$a->uuid, $b->uuid, $a->uuid]])->assertOk()->assertJsonPath('added', 2);
        $this->actingAs($mm)->postJson('/api/assets-bulk', ['uuids' => [$b->uuid, $c->uuid], 'album_uuid' => $uuid, 'add_tags' => ['Best']])->assertOk();
        $this->assertSame(3, Album::first()->assets()->count());
        $this->assertSame(2, Tag::where('slug', 'best')->first()->assets()->count());

        $this->actingAs($mm)->putJson("/api/albums/{$uuid}/order", ['uuids' => [$c->uuid, $a->uuid, $b->uuid]])->assertOk();
        $this->actingAs($mm)->patchJson("/api/albums/{$uuid}", ['cover_uuid' => $b->uuid])->assertOk()->assertJsonPath('album.cover_uuid', $b->uuid);
        $this->actingAs($mm)->getJson("/api/albums/{$uuid}")->assertOk()->assertJsonPath('assets.0.uuid', $c->uuid)->assertJsonPath('album.asset_count', 3);

        $this->actingAs($mm)->deleteJson("/api/albums/{$uuid}/assets/{$b->uuid}")->assertOk();
        $this->assertNull(Album::first()->cover_asset_id);
        $this->actingAs($mm)->getJson('/api/albums')->assertJsonPath('data.0.asset_count', 2);
        $this->actingAs(User::factory()->role('management')->create())->postJson('/api/albums', ['title' => 'x'])->assertForbidden();
    }

    public function test_taxonomy_crud_slug_and_in_use_guard(): void
    {
        $mm = User::factory()->role('multimedia')->create();
        $id = $this->actingAs($mm)->postJson('/api/taxonomy/events', ['title' => 'Film Festival', 'occurred_on' => '2026-11-02'])->assertCreated()->json('data.id');
        $this->assertSame('film-festival-2026', Event::find($id)->slug);
        $this->actingAs($mm)->postJson('/api/taxonomy/events', ['title' => 'Film Festival', 'occurred_on' => '2026-11-02'])->assertCreated()->assertJsonPath('data.slug', 'film-festival-2026-2');
        $this->actingAs($mm)->postJson('/api/taxonomy/cohorts', ['name' => 'Cohort 4', 'code' => 'C4', 'year' => 2026])->assertCreated();
        $this->actingAs($mm)->postJson('/api/taxonomy/cohorts', ['name' => 'Again', 'code' => 'C4'])->assertStatus(422);
        $this->actingAs($mm)->postJson('/api/taxonomy/nope', ['name' => 'x'])->assertNotFound();
        Asset::factory()->create(['event_id' => $id]);
        $this->actingAs($mm)->deleteJson("/api/taxonomy/events/{$id}")->assertStatus(422);
        $this->actingAs($mm)->getJson('/api/taxonomy/events')->assertOk()->assertJsonPath('data.1.assets_count', 1);
        $this->actingAs($mm)->getJson('/api/lookups')->assertOk()->assertJsonCount(2, 'events')->assertJsonCount(1, 'cohorts');
    }
}
