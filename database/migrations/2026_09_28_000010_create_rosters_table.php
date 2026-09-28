<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rosters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('tournament_id');
            $table->foreignId('player_id')->constrained('players');
            $table->unsignedTinyInteger('jersey_number');
            $table->enum('status', ['active', 'removed'])->default('active');
            $table->timestamps();

            // Regla crítica: un jugador solo puede estar en un equipo por torneo.
            $table->unique(['player_id', 'tournament_id']);
            $table->unique(['registration_id', 'jersey_number']);
            $table->foreign(['registration_id', 'tournament_id'])
                ->references(['id', 'tournament_id'])->on('registrations');
            $table->foreign('tournament_id')->references('id')->on('tournaments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};
