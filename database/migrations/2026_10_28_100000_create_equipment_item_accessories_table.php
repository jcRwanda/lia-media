<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_item_accessories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->foreignId('accessory_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['equipment_item_id', 'accessory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_item_accessories');
    }
};
