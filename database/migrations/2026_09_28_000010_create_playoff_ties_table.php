<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un registro por cruce de liguilla (no por partido). `registration_a_id` es el mejor sembrado en la primera ronda;
     * en las siguientes, A es el ganador del cruce impar de la ronda anterior y B el del par. Nulos hasta definirse.
     */
    public function up(): void
    {
        Schema::create('playoff_ties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('tournament_groups');
            $table->unsignedTinyInteger('round_index');
            $table->unsignedSmallInteger('bracket_position');
            $table->foreignId('registration_a_id')->nullable()->constrained('registrations');
            $table->foreignId('registration_b_id')->nullable()->constrained('registrations');
            $table->unsignedSmallInteger('seed_a')->nullable();
            $table->unsignedSmallInteger('seed_b')->nullable();
            $table->foreignId('winner_registration_id')->nullable()->constrained('registrations');
            $table->enum('decided_by', ['goals', 'penalties'])->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'round_index', 'bracket_position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playoff_ties');
    }
};
