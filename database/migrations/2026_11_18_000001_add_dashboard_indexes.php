<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Índices para las cifras del panel global: partidos de la semana, inscripciones y plantillas activas por torneo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->index(['match_datetime', 'status'], 'matches_datetime_status_index');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->index(['tournament_id', 'status'], 'registrations_tournament_status_index');
        });

        Schema::table('rosters', function (Blueprint $table) {
            $table->index(['tournament_id', 'status'], 'rosters_tournament_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropIndex('matches_datetime_status_index');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropIndex('registrations_tournament_status_index');
        });

        Schema::table('rosters', function (Blueprint $table) {
            $table->dropIndex('rosters_tournament_status_index');
        });
    }
};
