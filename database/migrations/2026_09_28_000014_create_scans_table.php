<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Escaneos de QR del árbitro; se generan sin conexión y se sincronizan por client_uuid.
     */
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('match_id')->constrained('matches');
            $table->foreignId('scanned_by_user_id')->constrained('users');
            $table->string('scanned_code', 255);
            $table->foreignId('player_id')->nullable()->constrained('players');
            $table->foreignId('registration_id')->nullable()->constrained('registrations');
            // banned = bloqueo de liga activo; suspended = sanción activa en el torneo.
            $table->enum('result', ['valid', 'not_registered', 'removed', 'unknown_qr', 'banned', 'suspended']);
            $table->dateTime('scanned_at');
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->index(['match_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
