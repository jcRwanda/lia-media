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
            $table->string('project_name')->nullable()->after('email');
            $table->string('village_res')->nullable()->after('cell_res');
            $table->string('relationship')->nullable()->after('emergency_contact_phone');
            $table->boolean('consent_declaration')->default(false)->after('terms_accepted');
            $table->foreignId('cohort_id')->nullable()->after('program_cohort')->constrained('cohorts')->nullOnDelete();
            $table->string('id_document_path')->nullable()->after('cohort_id');
            $table->string('id_document_mime')->nullable()->after('id_document_path');
            $table->string('id_document_name')->nullable()->after('id_document_mime');
            $table->string('signature_path')->nullable()->after('id_document_name');
            $table->date('signature_date')->nullable()->after('signature_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cohort_id');
            $table->dropColumn([
                'project_name',
                'village_res',
                'relationship',
                'consent_declaration',
                'id_document_path',
                'id_document_mime',
                'id_document_name',
                'signature_path',
                'signature_date',
            ]);
        });
    }
};
