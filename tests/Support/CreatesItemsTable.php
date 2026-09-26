<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesItemsTable
{
    protected function createItemsTable(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('status')->nullable();
            $table->timestamps();
        });
    }

    protected function createUuidItemsTable(): void
    {
        Schema::create('uuid_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function seedItems(): void
    {
        Item::create(['name' => 'Ana', 'status' => 1]);
        Item::create(['name' => 'Beto', 'status' => 0]);
        Item::create(['name' => 'Carlos', 'status' => 1]);
    }
}
