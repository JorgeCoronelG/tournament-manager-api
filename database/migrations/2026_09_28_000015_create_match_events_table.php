<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goles, tarjetas y cambios registrados por el árbitro; se generan sin conexión y se sincronizan por client_uuid.
     * No se borran filas: un evento capturado por error se marca is_voided.
     */
    public function up(): void
    {
        Schema::create('match_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('match_id')->constrained('matches');
            $table->foreignId('registration_id')->constrained('registrations');
            $table->foreignId('player_id')->nullable()->constrained('players');
            $table->foreignId('related_player_id')->nullable()->constrained('players');
            $table->enum('type', ['goal', 'own_goal', 'yellow_card', 'red_card', 'substitution']);
            $table->unsignedTinyInteger('minute')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->dateTime('recorded_at');
            $table->dateTime('synced_at')->nullable();
            $table->boolean('is_voided')->default(false);
            $table->timestamps();

            $table->index(['match_id', 'type']);
            $table->index(['player_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_events');
    }
};
