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
            $table->foreignId('home_registration_id')->constrained('registrations');
            $table->foreignId('away_registration_id')->constrained('registrations');
            // Nullable: se puede programar antes de asignar árbitro o cancha.
            $table->foreignId('referee_user_id')->nullable()->constrained('users');
            $table->foreignId('field_id')->nullable()->constrained('fields');
            $table->enum('phase', ['regular_stage', 'round_of_16', 'quarterfinal', 'semifinal', 'final']);
            $table->unsignedSmallInteger('matchday')->nullable();
            $table->dateTime('match_datetime');
            $table->dateTime('original_datetime')->nullable();
            $table->enum('status', ['scheduled', 'played', 'cancelled'])->default('scheduled');
            // Nullable: un partido programado aún no tiene marcador.
            $table->unsignedTinyInteger('home_score')->nullable();
            $table->unsignedTinyInteger('away_score')->nullable();
            $table->unsignedTinyInteger('home_penalties')->nullable();
            $table->unsignedTinyInteger('away_penalties')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tournament_id', 'phase', 'status']);
            $table->index(['referee_user_id', 'match_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
