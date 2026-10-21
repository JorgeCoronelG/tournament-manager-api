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
            // pending: solicitud del delegado; active: el jugador aceptó; rejected: lo rechazó; cancelled: se canceló
            // sola (aceptó otra solicitud del mismo torneo o se cerraron las plantillas); removed: lo quitó el admin.
            $table->enum('status', ['pending', 'active', 'rejected', 'cancelled', 'removed'])->default('pending');
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason', 255)->nullable();
            // Solo en filas removed: el admin autoriza que el jugador se sume a otro equipo del mismo torneo.
            $table->timestamp('rejoin_authorized_at')->nullable();
            $table->timestamps();

            // MySQL no tiene índices parciales: estas columnas valen NULL cuando la fila no cuenta y NULL no choca
            // con NULL. Un jugador solo está activo en un equipo por torneo, y un dorsal solo se repite si la otra
            // fila ya no está abierta (pending o active).
            $table->unsignedBigInteger('active_player_key')->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN player_id END");
            $table->unsignedTinyInteger('open_jersey_key')->nullable()
                ->storedAs("CASE WHEN status IN ('pending', 'active') THEN jersey_number END");
            $table->unique(['active_player_key', 'tournament_id']);
            $table->unique(['registration_id', 'open_jersey_key']);
            $table->foreign(['registration_id', 'tournament_id'])
                ->references(['id', 'tournament_id'])->on('registrations');
            $table->index(['player_id', 'tournament_id']);
            $table->foreign('tournament_id')->references('id')->on('tournaments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};
