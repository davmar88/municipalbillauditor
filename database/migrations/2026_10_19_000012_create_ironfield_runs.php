<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goes at Ironfield's missions, so each region's men stay on guard for a
 * while after one, and the valley can see who has been in deep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ironfield_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('depth');
            $table->unsignedTinyInteger('horseshoes');
            $table->boolean('won');
            $table->timestamp('created_at')->nullable();

            $table->index(['player_id', 'region_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ironfield_runs');
    }
};
