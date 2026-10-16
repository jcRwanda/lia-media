<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Asset;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Models\Person;
use App\Models\Project;
use App\Models\ShareLink;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tables_exist(): void
    {
        foreach (['projects', 'cohorts', 'events', 'categories', 'assets', 'asset_derivatives', 'tags', 'asset_tag', 'albums', 'album_asset', 'upload_sessions', 'upload_parts', 'share_links', 'share_recipients', 'access_grants', 'share_access_attempts', 'people', 'consent_records', 'asset_person', 'publication_usages', 'review_events', 'settings', 'audit_logs'] as $t) {
            $this->assertTrue(Schema::hasTable($t), $t);
        }
    }

    public function test_relations_round_trip(): void
    {
        $u = User::factory()->create();
        $p = Project::query()->create(['name' => 'Film Incubation', 'slug' => 'film-incubation', 'created_by' => $u->id]);
        $e = Event::query()->create(['title' => 'Graduation', 'slug' => 'graduation-2026', 'project_id' => $p->id, 'occurred_on' => '2026-10-01']);
        $a = Asset::query()->create(['kind' => 'image', 'title' => 'Stage', 'original_filename' => 's.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'storage_key' => 'originals/x/s.jpg', 'event_id' => $e->id, 'project_id' => $p->id, 'uploaded_by' => $u->id]);
        $this->assertNotEmpty($a->uuid);
        $this->assertSame('unreviewed', $a->fresh()->review_state);
        $a->tags()->attach(Tag::query()->create(['name' => 'Stage', 'slug' => 'stage']));
        $al = Album::query()->create(['title' => 'Best of', 'created_by' => $u->id]);
        $al->assets()->attach($a->id, ['position' => 1]);
        $person = Person::query()->create(['full_name' => 'A Person', 'contact' => '+250 7xx']);
        $c = ConsentRecord::query()->create(['person_id' => $person->id, 'scope' => 'photo', 'signed_on' => '2026-09-01']);
        $a->people()->attach($person->id, ['consent_record_id' => $c->id]);
        $this->assertTrue($c->isActive());
        $this->assertNotSame('+250 7xx', \DB::table('people')->value('contact')); // encrypted at rest
        $s = ShareLink::query()->create(['token_hash' => ShareLink::hashToken('t'), 'shareable_type' => 'album', 'shareable_id' => $al->id, 'mode' => 'public']);
        $this->assertTrue($s->isLive());
        $this->assertSame(1, $s->target()->assets()->count());
        $this->assertSame('image', Asset::kindFromMime('image/heic'));
        $this->assertSame('video', Asset::kindFromMime('video/mp4'));
    }

    public function test_index_and_fk_names_fit_mysql_limit(): void
    {
        $src = file_get_contents(database_path('migrations/2026_10_16_120000_create_archive_schema.php'));
        preg_match_all("/'([a-z_]+_(?:fk|idx|uq|pk))'/", $src, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $name) {
            $this->assertLessThan(64, strlen($name), $name);
        }
        $this->assertSame(count($m[1]), count(array_unique($m[1])), 'duplicate constraint names');
    }
}
