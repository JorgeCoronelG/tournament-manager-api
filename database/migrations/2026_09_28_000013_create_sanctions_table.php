<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players');
            $table->foreignId('tournament_id')->constrained('tournaments');
            $table->foreignId('match_id')->nullable()->constrained('matches');
            $table->unsignedTinyInteger('suspension_matches')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->boolean('is_permanent')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tournament_id', 'player_id', 'is_completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanctions');
    }
};
