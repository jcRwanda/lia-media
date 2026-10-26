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
            $table->string('final_work_status')->default('not_required')->after('status');
            $table->timestamp('final_work_submitted_at')->nullable()->after('final_work_status');
            $table->timestamp('final_work_reviewed_at')->nullable()->after('final_work_submitted_at');
            $table->foreignId('final_work_reviewed_by')->nullable()->after('final_work_reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('final_work_review_note')->nullable()->after('final_work_reviewed_by');
            $table->index('final_work_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('final_work_reviewed_by');
            $table->dropIndex(['final_work_status']);
            $table->dropColumn([
                'final_work_status',
                'final_work_submitted_at',
                'final_work_reviewed_at',
                'final_work_review_note',
            ]);
        });
    }
};
