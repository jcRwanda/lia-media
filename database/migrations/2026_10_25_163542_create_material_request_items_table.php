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
        Schema::create('material_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_requested')->default(1);
            $table->unsignedInteger('qty_approved')->nullable();
            $table->unsignedInteger('qty_issued')->nullable();
            $table->unsignedInteger('qty_returned')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, issued, returned, closed
            $table->text('note')->nullable();
            // issue
            $table->string('issue_proof_path')->nullable();
            $table->string('issue_condition')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            // return
            $table->string('return_proof_path')->nullable();
            $table->string('return_condition')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_request_items');
    }
};
