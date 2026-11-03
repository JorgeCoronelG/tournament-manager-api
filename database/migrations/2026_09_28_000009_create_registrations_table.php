<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams');
            $table->foreignId('tournament_id')->constrained('tournaments');
            $table->foreignId('group_id')->nullable()->constrained('tournament_groups');
            $table->enum('status', ['active', 'withdrawn'])->default('active');
            // Solo en bajas: cuándo, por qué y quién dio de baja al equipo.
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('withdrawn_reason', 255)->nullable();
            $table->foreignId('withdrawn_by_user_id')->nullable()->constrained('users');
            // Solo en inscripciones quitadas en planeación: quién y por qué (cuándo es deleted_at).
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users');
            $table->string('removal_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Se conserva el historial de eliminaciones: un equipo puede tener varias filas eliminadas en el torneo, pero
            // solo una no eliminada (activa o en baja). MySQL no tiene índices parciales: la columna vale 1 mientras la
            // fila no está eliminada y NULL después, y NULL no choca con NULL.
            $table->unsignedTinyInteger('active_registration_key')->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN 1 END');
            $table->unique(['team_id', 'tournament_id', 'active_registration_key'], 'registrations_active_team_unique');
            // Destino de la FK compuesta de rosters: garantiza que rosters.tournament_id coincida con la inscripción.
            $table->unique(['id', 'tournament_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
