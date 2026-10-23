<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orden manual de un conjunto de equipos que siguen empatados en la tabla tras aplicar todos los criterios.
     * `position` va de 1 a n dentro de cada conjunto; solo se aplica mientras el empate siga siendo el mismo.
     */
    public function up(): void
    {
        Schema::create('standing_manual_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('tournament_groups')->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['group_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standing_manual_orders');
    }
};
