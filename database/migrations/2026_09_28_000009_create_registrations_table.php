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
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'tournament_id']);
            // Destino de la FK compuesta de rosters: garantiza que rosters.tournament_id coincida con la inscripción.
            $table->unique(['id', 'tournament_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
