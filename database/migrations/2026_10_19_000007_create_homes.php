<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every animal's own home on the map: where it stands, how far it is built
 * up (Hut, Cottage, Fort), the skin it wears, and whether its animal is
 * hiding inside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->cascadeOnDelete();
            $table->float('x');
            $table->float('y');
            $table->unsignedTinyInteger('level')->default(1);
            // While a level goes up; null once it stands.
            $table->timestamp('ready_at')->nullable();
            $table->foreignId('skin_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('players', function (Blueprint $table) {
            // When it went inside its home to hide; null while it's out.
            $table->timestamp('inside_home_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn('inside_home_at');
        });
        Schema::dropIfExists('homes');
    }
};
