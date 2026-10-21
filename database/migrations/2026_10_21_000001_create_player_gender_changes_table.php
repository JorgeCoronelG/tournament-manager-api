<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría de la corrección de rama: quién, cuándo y de qué valor a cuál.
     */
    public function up(): void
    {
        Schema::create('player_gender_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players');
            $table->foreignId('changed_by_user_id')->constrained('users');
            $table->enum('old_gender', ['male', 'female']);
            $table->enum('new_gender', ['male', 'female']);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_gender_changes');
    }
};
