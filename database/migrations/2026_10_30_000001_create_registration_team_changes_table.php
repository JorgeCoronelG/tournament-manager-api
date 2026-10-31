<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de reemplazos de equipo: la inscripción conserva su fila y cambia de equipo.
     */
    public function up(): void
    {
        Schema::create('registration_team_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('registrations');
            $table->foreignId('old_team_id')->constrained('teams');
            $table->foreignId('new_team_id')->constrained('teams');
            $table->string('reason', 255);
            $table->foreignId('changed_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_team_changes');
    }
};
