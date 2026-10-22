<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_league_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players');
            $table->foreignId('league_id')->constrained('leagues');
            $table->text('reason');
            $table->foreignId('banned_by_user_id')->constrained('users');
            // El bloqueo aplica mientras lifted_at sea NULL; levantarlo no borra el historial. La fecha del veto es created_at.
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['league_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_league_bans');
    }
};
