<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_request_items', function (Blueprint $table) {
            $table->boolean('added_at_pickup')->default(false)->after('note');
            $table->foreignId('added_by')->nullable()->after('added_at_pickup')->constrained('users')->nullOnDelete();
            $table->timestamp('added_at')->nullable()->after('added_by');
        });
    }

    public function down(): void
    {
        Schema::table('material_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
            $table->dropColumn(['added_at_pickup', 'added_at']);
        });
    }
};
