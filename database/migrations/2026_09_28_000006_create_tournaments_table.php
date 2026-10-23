<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues');
            $table->string('name', 150);
            // Un solo deporte por ahora; para agregar otro se amplía el enum (ver App\Core\Enum\Sport).
            $table->enum('sport', ['football'])->default('football');
            $table->enum('gender', ['male', 'female', 'mixed']);
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age')->nullable();
            // Máximo de jugadores por equipo (cuentan active y pending); NULL = sin límite.
            $table->unsignedSmallInteger('max_roster_size')->nullable();
            // Partidos mínimos con asistencia en la fase regular para jugar la liguilla; NULL = no aplica.
            $table->unsignedSmallInteger('min_matches_for_playoffs')->nullable();
            $table->date('start_date');
            $table->enum('status', ['planning', 'in_progress', 'finished'])->default('planning');
            // Vueltas de la fase regular: 1 o 2.
            $table->unsignedTinyInteger('rounds')->default(1);
            // Duración fija del partido (20 a 180), con medios tiempos y descansos incluidos.
            $table->unsignedSmallInteger('match_duration_minutes')->default(60);
            // Criterios de desempate en orden (ver App\Core\Enum\Tiebreaker). MySQL no admite default en json: lo asigna la aplicación.
            $table->json('tiebreakers');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
