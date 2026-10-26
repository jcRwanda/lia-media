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
            $table->text('webhook_url')->nullable()->after('allowed_origin');
        });

        Schema::table('material_requests', function (Blueprint $table) {
            $table->foreignId('api_client_id')->nullable()->after('reference_code')->constrained('api_clients')->nullOnDelete();
            $table->string('source')->nullable()->after('api_client_id');
            $table->string('source_detail')->nullable()->after('source');
            $table->index('source');
            $table->index(['status', 'needed_from', 'return_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'needed_from', 'return_by']);
            $table->dropIndex(['source']);
            $table->dropConstrainedForeignId('api_client_id');
            $table->dropColumn(['source', 'source_detail']);
        });

        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropColumn('webhook_url');
        });
    }
};
