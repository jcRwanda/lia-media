<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->boolean('allows_alumni_certificate_exempt')->default(false)->after('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropColumn('allows_alumni_certificate_exempt');
        });
    }
};
