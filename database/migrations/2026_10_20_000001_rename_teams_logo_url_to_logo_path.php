<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Se guarda la ruta relativa en el disco public; la URL completa se arma al responder.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->renameColumn('logo_url', 'logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->renameColumn('logo_path', 'logo_url');
        });
    }
};
