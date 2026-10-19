<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letting visitors download is the common case for a gallery link, so new links default to allowing
 * it (staff can still turn it off in share settings). Existing links keep the value chosen when they
 * were created; only the column default changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $t) {
            $t->boolean('allow_download')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $t) {
            $t->boolean('allow_download')->default(false)->change();
        });
    }
};
