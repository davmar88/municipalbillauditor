<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staying alive: hunger, thirst and health for every animal, a water bag to
 * carry water in, health for homes, and the loot dead animals leave behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->decimal('food', 6, 2)->default(80);
            $table->decimal('drink', 6, 2)->default(80);
            $table->decimal('health', 6, 2)->default(100);
            $table->timestamp('vitals_at')->nullable();
            $table->unsignedTinyInteger('water_barrel')->default(0);
            $table->timestamp('died_at')->nullable();
            $table->unsignedInteger('deaths')->default(0);
        });

        Schema::table('homes', function (Blueprint $table) {
            // Health a home was left with after a fire was put out, mending from health_at.
            $table->decimal('health', 6, 2)->nullable();
            $table->timestamp('health_at')->nullable();
        });

        Schema::create('loot_drops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('valley_id')->constrained()->cascadeOnDelete();
            // Whose it was.
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->float('x');
            $table->float('y');
            $table->unsignedInteger('corn')->default(0);
            $table->json('materials')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('picked_by')->nullable()->constrained('players')->nullOnDelete();
            $table->timestamp('picked_at')->nullable();
            $table->timestamps();
            $table->index(['valley_id', 'picked_at', 'expires_at']);
        });

        // Everyone starts fed and watered, with a little water in their bag.
        DB::table('players')->update(['vitals_at' => now()]);
        DB::table('players')->get(['id', 'materials'])->each(function (object $player) {
            $materials = json_decode((string) $player->materials, true) ?: [];
            $materials['water'] = max(20, (int) ($materials['water'] ?? 0));
            DB::table('players')->where('id', $player->id)->update(['materials' => json_encode($materials)]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loot_drops');
        Schema::table('homes', fn (Blueprint $table) => $table->dropColumn(['health', 'health_at']));
        Schema::table('players', fn (Blueprint $table) => $table->dropColumn(['food', 'drink', 'health', 'vitals_at', 'water_barrel', 'died_at', 'deaths']));
    }
};
