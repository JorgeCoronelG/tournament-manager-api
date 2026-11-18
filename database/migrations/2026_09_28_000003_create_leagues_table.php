<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained('users');
            $table->string('name', 150);
            $table->enum('status', ['active', 'inactive'])->default('active');
            // Límite de torneos activos (en curso o en liguilla) por liga; NULL = sin límite.
            $table->unsignedSmallInteger('max_active_tournaments')->nullable();
            // Reservado para los planes futuros: hoy no se configura (NULL = sin límite).
            $table->unsignedSmallInteger('max_teams_per_tournament')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};
