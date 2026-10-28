<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->foreignId('material_request_id')->nullable()->constrained('material_requests')->nullOnDelete();
            $table->foreignId('material_request_item_id')->nullable()->constrained('material_request_items')->nullOnDelete();
            $table->string('type', 32); // pickup_confirm | issue_check | return_check
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('borrower_name')->nullable();
            $table->string('verified_via', 16)->nullable();
            $table->boolean('serial_matches')->nullable();
            $table->string('serial_seen')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            $table->index(['material_request_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_checks');
    }
};
