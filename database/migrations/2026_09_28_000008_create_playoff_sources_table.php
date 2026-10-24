<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * De dónde salen los equipos de cada liguilla: posiciones position_start a position_end de un grupo de origen.
     * Que position_start sea al menos 1 y position_end no menor se valida en la aplicación, no con CHECK (los tests corren en SQLite).
     */
    public function up(): void
    {
        Schema::create('playoff_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playoff_group_id')->constrained('tournament_groups')->cascadeOnDelete();
            // 'range': posiciones position_start a position_end de source_group_id. 'best_of_position': los best_count mejores
            // equipos que quedaron en esa posición (position_start = position_end) entre los grupos candidatos; sin grupo de origen.
            $table->enum('kind', ['range', 'best_of_position'])->default('range');
            $table->foreignId('source_group_id')->nullable()->constrained('tournament_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('position_start');
            $table->unsignedSmallInteger('position_end');
            $table->unsignedSmallInteger('best_count')->nullable();
            $table->timestamps();

            $table->unique(['playoff_group_id', 'source_group_id', 'position_start'], 'playoff_sources_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playoff_sources');
    }
};
