<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->string('qr_token', 40)->nullable()->unique()->after('asset_tag');
        });

        DB::table('equipment_items')->whereNull('qr_token')->orderBy('id')->pluck('id')->each(function ($id) {
            DB::table('equipment_items')->where('id', $id)->update(['qr_token' => Str::random(32)]);
        });
    }

    public function down(): void
    {
        Schema::table('equipment_items', function (Blueprint $table) {
            $table->dropUnique(['qr_token']);
            $table->dropColumn('qr_token');
        });
    }
};
