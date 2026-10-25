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
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_code')->unique();
            $table->string('requester_type'); // client, trainee, alumni
            $table->string('full_name');
            $table->string('phone');
            $table->string('email');
            $table->string('national_id')->nullable();
            $table->string('province_res')->nullable();
            $table->string('district_res')->nullable();
            $table->string('sector_res')->nullable();
            $table->string('cell_res')->nullable();
            $table->string('residential_address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->text('purpose_project_description')->nullable();
            $table->string('project_province')->nullable();
            $table->string('project_district')->nullable();
            $table->string('project_sector')->nullable();
            $table->string('project_cell')->nullable();
            $table->string('project_location')->nullable();
            $table->date('needed_from')->nullable();
            $table->date('return_by')->nullable();
            $table->boolean('terms_accepted')->default(false);

            // trainee/alumni only
            $table->string('program_cohort')->nullable();
            $table->string('graduation_completion_year')->nullable();
            $table->string('student_alumni_id')->nullable();
            $table->string('project_title')->nullable();
            $table->string('project_type')->nullable();
            $table->text('expected_outcome')->nullable();
            $table->string('portfolio_social_link')->nullable();

            $table->string('status')->default('submitted'); // submitted, approved, rejected, issued, returned, closed
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'requester_type']);
            $table->index('reference_code');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
