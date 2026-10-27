<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_schedule_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments');
            // 1 = lunes ... 7 = domingo. Una cancha puede tener varias ventanas el mismo día, sin empalmarse.
            $table->unsignedTinyInteger('weekday');
            $table->foreignId('field_id')->constrained('fields');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['tournament_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_schedule_windows');
    }
};
