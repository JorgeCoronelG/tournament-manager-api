<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columnas que pide el modo árbitro de la app móvil.
     */
    public function up(): void
    {
        Schema::table('match_events', function (Blueprint $table) {
            // El reloj del árbitro vuelve a 0 en cada tiempo: el minuto solo se entiende junto con el tiempo en que pasó.
            // extra_first y extra_second son el tiempo extra de las finales empatadas.
            $table->enum('period', ['first_half', 'second_half', 'extra_first', 'extra_second'])->nullable()->after('minute');
        });

        Schema::table('scans', function (Blueprint $table) {
            // qr = escaneó el código; manual = jugador que llegó tarde o sin celular, validado por nombre y foto.
            $table->enum('method', ['qr', 'manual'])->default('qr')->after('scanned_code');
        });
    }

    public function down(): void
    {
        Schema::table('match_events', function (Blueprint $table) {
            $table->dropColumn('period');
        });

        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
