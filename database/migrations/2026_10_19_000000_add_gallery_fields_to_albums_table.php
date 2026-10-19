<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Day galleries and the catch-all default album share the existing albums table.
 * `kind` distinguishes curated albums from the automatic per-day galleries, `day` holds the
 * calendar date (Kigali time) of a day gallery, and `is_default` flags the single fallback album
 * new uploads land in. MariaDB/MySQL allow many NULLs in the unique `day` index, so every curated
 * album (day = NULL) can coexist with at most one gallery per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $t) {
            $t->string('kind', 12)->default('curated')->after('title'); // curated | daily
            $t->date('day')->nullable()->after('kind');
            $t->boolean('is_default')->default(false)->after('day');
            $t->unique('day', 'albums_day_uq');
            $t->index(['kind', 'day'], 'albums_kind_day_idx');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $t) {
            $t->dropUnique('albums_day_uq');
            $t->dropIndex('albums_kind_day_idx');
            $t->dropColumn(['kind', 'day', 'is_default']);
        });
    }
};
