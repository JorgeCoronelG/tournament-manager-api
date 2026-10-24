<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orden manual de los candidatos empatados de una fuente 'best_of_position' (comparativa de los mejores). `sort_order`
     * va de 1 a n dentro del conjunto empatado; solo se aplica mientras el empate siga siendo el mismo.
     */
    public function up(): void
    {
        Schema::create('best_position_manual_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('tournament_groups')->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('playoff_sources')->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['source_id', 'registration_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('best_position_manual_orders');
    }
};
