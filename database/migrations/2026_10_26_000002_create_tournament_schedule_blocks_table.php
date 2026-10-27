<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained('tournaments');
            $table->date('date');
            // Nulo = todas las canchas.
            $table->foreignId('field_id')->nullable()->constrained('fields');
            // Nulos = todo el día.
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason', 150)->nullable();
            $table->timestamps();

            $table->index(['tournament_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_schedule_blocks');
    }
};
