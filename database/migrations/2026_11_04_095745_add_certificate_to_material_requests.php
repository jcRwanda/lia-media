<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('certificate_path')->nullable()->after('id_document_name');
            $table->string('certificate_mime')->nullable()->after('certificate_path');
            $table->string('certificate_name')->nullable()->after('certificate_mime');
            $table->string('certificate_url', 2048)->nullable()->after('certificate_name');
            $table->string('certificate_source', 32)->nullable()->after('certificate_url'); // uploaded, link, reused
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropColumn(['certificate_path', 'certificate_mime', 'certificate_name', 'certificate_url', 'certificate_source']);
        });
    }
};
