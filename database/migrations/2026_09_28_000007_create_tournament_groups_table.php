<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `groups` del modelo, renombrada a `tournament_groups` porque GROUPS es palabra reservada en MySQL 8.
     */
    public function up(): void
    {
        Schema::create('tournament_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments');
            $table->string('name', 100);
            $table->enum('type', ['regular_stage', 'playoff']);
            $table->foreignId('source_group_id')->nullable()->constrained('tournament_groups');
            $table->unsignedSmallInteger('position_start')->nullable();
            $table->unsignedSmallInteger('position_end')->nullable();
            $table->unsignedSmallInteger('playoff_teams')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_groups');
    }
};
