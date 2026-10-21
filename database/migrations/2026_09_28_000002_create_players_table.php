<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->date('birth_date');
            // Rama del jugador (male = Varonil, female = Femenil); mismos valores que tournaments.gender para compararlos directo.
            $table->enum('gender', ['male', 'female']);
            // Valor aleatorio (no el id) para que no sea predecible; se puede regenerar si se filtra.
            $table->string('qr_code', 64)->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
