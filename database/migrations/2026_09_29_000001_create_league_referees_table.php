<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('league_referees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained('leagues');
            // El usuario debe tener rol `referee`; se valida en la app, no aquí.
            $table->foreignId('user_id')->constrained('users');
            // Dar de baja a un árbitro en la liga solo cambia este status; no toca
            // su cuenta, sus roles ni el historial de partidos.
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['league_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_referees');
    }
};
