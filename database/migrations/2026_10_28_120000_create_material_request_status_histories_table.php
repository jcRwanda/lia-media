<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A first deploy attempt created the table but failed on the index name; start clean.
        Schema::dropIfExists('material_request_status_histories');
        Schema::create('material_request_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20)->default('user');
            $table->timestamp('created_at')->nullable();
            $table->index(['material_request_id', 'created_at'], 'mr_status_hist_req_created_idx');
        });

        // Existing requests keep their current status; seed one history row so the timeline is never empty.
        DB::table('material_requests')->orderBy('id')->chunkById(200, function ($rows) {
            $insert = [];
            foreach ($rows as $r) {
                $insert[] = [
                    'material_request_id' => $r->id,
                    'from_status' => null,
                    'to_status' => $r->status,
                    'note' => $r->review_note ?? null,
                    'actor_id' => null,
                    'actor_type' => 'system',
                    'created_at' => $r->reviewed_at ?? $r->created_at,
                ];
            }
            if ($insert) {
                DB::table('material_request_status_histories')->insert($insert);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_status_histories');
    }
};
