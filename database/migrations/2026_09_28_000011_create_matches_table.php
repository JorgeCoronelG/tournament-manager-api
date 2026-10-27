<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments');
            $table->foreignId('group_id')->nullable()->constrained('tournament_groups');
            // Nullable: las rondas de liguilla se crean vacías hasta saber los ganadores.
            $table->foreignId('home_registration_id')->nullable()->constrained('registrations');
            $table->foreignId('away_registration_id')->nullable()->constrained('registrations');
            // Nullable: se puede programar antes de asignar árbitro o cancha.
            $table->foreignId('referee_user_id')->nullable()->constrained('users');
            $table->foreignId('field_id')->nullable()->constrained('fields');
            $table->enum('phase', ['regular_stage', 'round_of_32', 'round_of_16', 'quarterfinal', 'semifinal', 'final']);
            $table->unsignedSmallInteger('matchday')->nullable();
            // Vuelta a la que pertenece el partido (1 o 2).
            $table->unsignedTinyInteger('round_number')->default(1);
            // Solo liguilla: cruce (playoff_ties), ronda (1 = primera), partido de ida y vuelta (1 o 2; nulo si es único),
            // posición del cruce en la ronda y fecha programada (sin hora).
            $table->foreignId('tie_id')->nullable()->constrained('playoff_ties');
            $table->unsignedTinyInteger('round_index')->nullable();
            $table->unsignedTinyInteger('leg')->nullable();
            $table->unsignedSmallInteger('bracket_position')->nullable();
            $table->date('scheduled_date')->nullable();
            // Nullable: un partido puede estar por programar.
            $table->dateTime('match_datetime')->nullable();
            // Primer horario del partido antes de reprogramarlo a mano; se limpia si vuelve a ese horario.
            $table->dateTime('original_match_datetime')->nullable();
            $table->enum('status', ['scheduled', 'played', 'cancelled'])->default('scheduled');
            // Nullable: un partido programado aún no tiene marcador.
            $table->unsignedTinyInteger('home_score')->nullable();
            $table->unsignedTinyInteger('away_score')->nullable();
            $table->unsignedTinyInteger('home_penalties')->nullable();
            $table->unsignedTinyInteger('away_penalties')->nullable();
            $table->boolean('schedule_locked')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tournament_id', 'phase', 'status']);
            $table->index(['tournament_id', 'group_id', 'matchday']);
            $table->index(['referee_user_id', 'match_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
