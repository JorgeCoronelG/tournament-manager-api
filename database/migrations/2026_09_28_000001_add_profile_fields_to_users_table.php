<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extiende la tabla `users` de la base del API (no la recrea).
     * first_name, last_name y user_code quedan NULLABLE para no romper el seeder/factory
     * existentes; la API los exige al registrar.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('id');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('user_code', 20)->nullable()->unique()->after('last_name');
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('photo_url')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['user_code']);
            $table->dropUnique(['phone']);
            $table->dropColumn(['first_name', 'last_name', 'user_code', 'phone', 'photo_url', 'is_active']);
        });
    }
};
