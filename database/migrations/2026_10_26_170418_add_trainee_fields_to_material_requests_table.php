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
        Schema::table('material_requests', function (Blueprint $table) {
            $table->string('shoot_location')->nullable()->after('project_location');
            $table->string('shoot_type')->nullable()->after('shoot_location');
            $table->boolean('terms_credit')->default(false)->after('terms_accepted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropColumn(['shoot_location', 'shoot_type', 'terms_credit']);
        });
    }
};
