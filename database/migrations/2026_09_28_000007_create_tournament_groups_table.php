<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `groups` del modelo, renombrada a `tournament_groups` porque GROUPS es palabra reservada en MySQL 8.
     */
    public function up(): void
    {
        Schema::create('tournament_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments');
            $table->string('name', 100);
            $table->enum('type', ['regular_stage', 'playoff']);
            // Solo aplica a type playoff: fecha de la primera ronda y días entre rondas. Los equipos salen de playoff_sources.
            $table->date('start_date')->nullable();
            $table->unsignedSmallInteger('round_interval_days')->default(7);
            // Solo type playoff: lista JSON de fechas Y-m-d, una por partido de ronda en orden. Si es null se calculan con round_interval_days.
            $table->json('round_dates')->nullable();
            // Solo type playoff: lista JSON con un valor por ronda, 1 (un partido) o 2 (ida y vuelta). Null: todas a un partido.
            // round_dates trae una fecha por partido (suma de round_legs).
            $table->json('round_legs')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_groups');
    }
};
