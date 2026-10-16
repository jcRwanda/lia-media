<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Media archive schema. MariaDB 10.3 / MySQL 8 compatible: utf8mb4 string keys <= 191 chars,
 * explicit index/FK names (< 64 chars), no partial or functional indexes, JSON stored as LONGTEXT on MariaDB.
 */
return new class extends Migration
{
    private function fk(Blueprint $t, string $col, string $table, string $name, string $onDelete = 'set null', bool $nullable = true): void
    {
        $c = $t->unsignedBigInteger($col);
        if ($nullable) {
            $c->nullable();
        }
        $f = $t->foreign($col, $name)->references('id')->on($table);
        match ($onDelete) {
            'cascade' => $f->cascadeOnDelete(),
            'restrict' => $f->restrictOnDelete(),
            default => $f->nullOnDelete(),
        };
    }

    public function up(): void
    {
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->string('slug', 180)->unique('projects_slug_uq');
            $t->text('description')->nullable();
            $t->string('status', 20)->default('active');
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $this->fk($t, 'created_by', 'users', 'projects_created_by_fk');
            $t->timestamps();
        });
        Schema::create('cohorts', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->string('code', 40)->unique('cohorts_code_uq');
            $t->unsignedSmallInteger('year')->nullable();
            $this->fk($t, 'project_id', 'projects', 'cohorts_project_fk');
            $t->timestamps();
        });
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->string('title', 190);
            $t->string('slug', 190)->unique('events_slug_uq');
            $t->date('occurred_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->string('location', 190)->nullable();
            $t->text('description')->nullable();
            $this->fk($t, 'project_id', 'projects', 'events_project_fk');
            $this->fk($t, 'cohort_id', 'cohorts', 'events_cohort_fk');
            $t->timestamps();
            $t->index('occurred_on', 'events_occurred_idx');
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 140)->unique('categories_slug_uq');
            $this->fk($t, 'parent_id', 'categories', 'categories_parent_fk');
            $t->timestamps();
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique('assets_uuid_uq');
            $t->string('kind', 16); // image | video | audio | document | other
            $t->string('title', 190);
            $t->text('description')->nullable();
            $t->string('original_filename', 255);
            $t->string('mime_type', 120);
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->string('storage_key', 400);
            $t->string('storage_status', 16)->default('stored'); // stored | missing
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedBigInteger('duration_ms')->nullable();
            $t->timestamp('captured_at')->nullable();
            $t->string('credit', 190)->nullable();
            $this->fk($t, 'uploaded_by', 'users', 'assets_uploaded_by_fk');
            $this->fk($t, 'project_id', 'projects', 'assets_project_fk');
            $this->fk($t, 'cohort_id', 'cohorts', 'assets_cohort_fk');
            $this->fk($t, 'event_id', 'events', 'assets_event_fk');
            $this->fk($t, 'category_id', 'categories', 'assets_category_fk');
            $t->string('review_state', 16)->default('unreviewed'); // unreviewed|approved|published|restricted|archived
            $t->text('review_note')->nullable();
            $this->fk($t, 'reviewed_by', 'users', 'assets_reviewed_by_fk');
            $t->timestamp('reviewed_at')->nullable();
            $t->string('consent_status', 16)->default('unknown'); // unknown|covered|missing|withdrawn|not_needed
            $t->json('metadata')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['sha256', 'size_bytes'], 'assets_checksum_idx');
            $t->index(['review_state', 'created_at'], 'assets_review_idx');
            $t->index(['kind', 'created_at'], 'assets_kind_idx');
            $t->index('captured_at', 'assets_captured_idx');
            $t->index('deleted_at', 'assets_deleted_idx');
        });
        Schema::create('asset_derivatives', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'asset_id', 'assets', 'derivs_asset_fk', 'cascade', false);
            $t->string('type', 24); // thumb | preview | poster
            $t->string('storage_key', 400);
            $t->string('mime_type', 60);
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('status', 16)->default('pending'); // pending | ready | failed
            $t->timestamps();
            $t->unique(['asset_id', 'type'], 'derivs_asset_type_uq');
        });
        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('slug', 90)->unique('tags_slug_uq');
            $t->timestamps();
        });
        Schema::create('asset_tag', function (Blueprint $t) {
            $this->fk($t, 'asset_id', 'assets', 'asset_tag_asset_fk', 'cascade', false);
            $this->fk($t, 'tag_id', 'tags', 'asset_tag_tag_fk', 'cascade', false);
            $t->primary(['asset_id', 'tag_id'], 'asset_tag_pk');
            $t->index('tag_id', 'asset_tag_tag_idx');
        });

        Schema::create('albums', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique('albums_uuid_uq');
            $t->string('title', 190);
            $t->text('description')->nullable();
            $this->fk($t, 'project_id', 'projects', 'albums_project_fk');
            $this->fk($t, 'cohort_id', 'cohorts', 'albums_cohort_fk');
            $this->fk($t, 'event_id', 'events', 'albums_event_fk');
            $this->fk($t, 'cover_asset_id', 'assets', 'albums_cover_fk');
            $this->fk($t, 'created_by', 'users', 'albums_created_by_fk');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('album_asset', function (Blueprint $t) {
            $this->fk($t, 'album_id', 'albums', 'album_asset_album_fk', 'cascade', false);
            $this->fk($t, 'asset_id', 'assets', 'album_asset_asset_fk', 'cascade', false);
            $t->unsignedInteger('position')->default(0);
            $this->fk($t, 'added_by', 'users', 'album_asset_added_by_fk');
            $t->timestamp('added_at')->nullable();
            $t->primary(['album_id', 'asset_id'], 'album_asset_pk');
            $t->index(['album_id', 'position'], 'album_asset_pos_idx');
            $t->index('asset_id', 'album_asset_asset_idx');
        });

        Schema::create('upload_sessions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique('uploads_uuid_uq');
            $this->fk($t, 'user_id', 'users', 'uploads_user_fk', 'cascade', false);
            $t->string('filename', 255);
            $t->string('mime_type', 120);
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->string('storage_key', 400);
            $t->string('mode', 12); // single | multipart
            $t->string('upload_id', 255)->nullable(); // R2 multipart UploadId
            $t->unsignedInteger('part_size')->nullable();
            $t->string('status', 16)->default('pending'); // pending|uploading|completed|aborted|failed|duplicate
            $t->string('error', 255)->nullable();
            $this->fk($t, 'asset_id', 'assets', 'uploads_asset_fk');
            $t->json('context')->nullable(); // project/event/album/category/tags to apply on finalize
            $t->timestamp('expires_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'status'], 'uploads_user_status_idx');
            $t->index('expires_at', 'uploads_expires_idx');
            $t->index(['sha256', 'size_bytes'], 'uploads_checksum_idx');
        });
        Schema::create('upload_parts', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'upload_session_id', 'upload_sessions', 'upload_parts_session_fk', 'cascade', false);
            $t->unsignedInteger('part_number');
            $t->string('etag', 128);
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->timestamps();
            $t->unique(['upload_session_id', 'part_number'], 'upload_parts_uq');
        });

        Schema::create('share_links', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique('shares_uuid_uq');
            $t->char('token_hash', 64)->unique('shares_token_uq');
            $t->string('shareable_type', 20); // album | asset
            $t->unsignedBigInteger('shareable_id');
            $t->string('mode', 16); // public | access_code | restricted
            $t->string('access_code_hash', 255)->nullable();
            $t->string('title', 190)->nullable();
            $t->text('message')->nullable();
            $t->boolean('allow_download')->default(false);
            $t->boolean('include_originals')->default(false);
            $t->timestamp('expires_at')->nullable();
            $t->unsignedInteger('max_uses')->nullable();
            $t->unsignedInteger('use_count')->default(0);
            $t->timestamp('revoked_at')->nullable();
            $this->fk($t, 'revoked_by', 'users', 'shares_revoked_by_fk');
            $this->fk($t, 'created_by', 'users', 'shares_created_by_fk');
            $t->timestamp('last_accessed_at')->nullable();
            $t->timestamps();
            $t->index(['shareable_type', 'shareable_id'], 'shares_target_idx');
        });
        Schema::create('share_recipients', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'share_link_id', 'share_links', 'share_recip_link_fk', 'cascade', false);
            $t->string('email', 190);
            $t->string('name', 120)->nullable();
            $t->char('token_hash', 64)->unique('share_recip_token_uq');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_accessed_at')->nullable();
            $t->timestamps();
            $t->unique(['share_link_id', 'email'], 'share_recip_link_email_uq');
        });
        Schema::create('access_grants', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'share_link_id', 'share_links', 'grants_link_fk', 'cascade', false);
            $this->fk($t, 'share_recipient_id', 'share_recipients', 'grants_recipient_fk', 'cascade');
            $t->char('grant_hash', 64)->unique('grants_hash_uq');
            $t->string('ip', 45)->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
            $t->index(['share_link_id', 'expires_at'], 'grants_link_exp_idx');
        });
        Schema::create('share_access_attempts', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'share_link_id', 'share_links', 'share_attempts_link_fk', 'cascade', false);
            $t->string('ip', 45);
            $t->boolean('succeeded');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['share_link_id', 'created_at'], 'share_attempts_link_idx');
            $t->index(['ip', 'created_at'], 'share_attempts_ip_idx');
        });

        Schema::create('people', function (Blueprint $t) {
            $t->id();
            $t->string('full_name', 160);
            $t->string('organisation', 160)->nullable();
            $t->boolean('is_minor')->default(false);
            $t->text('contact')->nullable(); // encrypted cast
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index('full_name', 'people_name_idx');
        });
        Schema::create('consent_records', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'person_id', 'people', 'consent_person_fk', 'cascade', false);
            $t->string('scope', 30); // photo | video | photo_video | event_general
            $this->fk($t, 'event_id', 'events', 'consent_event_fk');
            $t->date('signed_on');
            $t->date('expires_on')->nullable();
            $t->string('guardian_name', 160)->nullable();
            $t->string('form_storage_key', 400)->nullable();
            $t->timestamp('withdrawn_at')->nullable();
            $t->text('withdrawn_reason')->nullable();
            $t->text('notes')->nullable();
            $this->fk($t, 'recorded_by', 'users', 'consent_recorded_by_fk');
            $t->timestamps();
            $t->index('expires_on', 'consent_expires_idx');
        });
        Schema::create('asset_person', function (Blueprint $t) {
            $this->fk($t, 'asset_id', 'assets', 'asset_person_asset_fk', 'cascade', false);
            $this->fk($t, 'person_id', 'people', 'asset_person_person_fk', 'cascade', false);
            $this->fk($t, 'consent_record_id', 'consent_records', 'asset_person_consent_fk');
            $t->primary(['asset_id', 'person_id'], 'asset_person_pk');
            $t->index('person_id', 'asset_person_person_idx');
        });
        Schema::create('publication_usages', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'asset_id', 'assets', 'pubs_asset_fk', 'cascade', false);
            $t->string('channel', 30); // website|instagram|facebook|x|youtube|press|print|report|other
            $t->string('url', 500)->nullable();
            $t->string('title', 190)->nullable();
            $t->date('published_on');
            $t->text('notes')->nullable();
            $this->fk($t, 'recorded_by', 'users', 'pubs_recorded_by_fk');
            $t->timestamps();
            $t->index(['channel', 'published_on'], 'pubs_channel_idx');
        });
        Schema::create('review_events', function (Blueprint $t) {
            $t->id();
            $this->fk($t, 'asset_id', 'assets', 'review_asset_fk', 'cascade', false);
            $t->string('from_state', 16);
            $t->string('to_state', 16);
            $t->text('note')->nullable();
            $this->fk($t, 'actor_id', 'users', 'review_actor_fk');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['asset_id', 'created_at'], 'review_asset_time_idx');
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'review_events', 'publication_usages', 'asset_person', 'consent_records', 'people', 'share_access_attempts', 'access_grants', 'share_recipients', 'share_links', 'upload_parts', 'upload_sessions', 'album_asset', 'albums', 'asset_tag', 'tags', 'asset_derivatives', 'assets', 'categories', 'events', 'cohorts', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
