<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('tournament_groups');
            $table->enum('phase', ['round_of_16', 'quarterfinal', 'semifinal', 'final']);
            $table->unsignedTinyInteger('position');
            $table->foreignId('source_bracket_a_id')->nullable()->constrained('brackets');
            $table->foreignId('source_bracket_b_id')->nullable()->constrained('brackets');
            $table->foreignId('registration_a_id')->nullable()->constrained('registrations');
            $table->foreignId('registration_b_id')->nullable()->constrained('registrations');
            $table->foreignId('winner_registration_id')->nullable()->constrained('registrations');
            $table->foreignId('match_id')->nullable()->unique()->constrained('matches');
            $table->timestamps();

            $table->unique(['group_id', 'phase', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brackets');
    }
};
