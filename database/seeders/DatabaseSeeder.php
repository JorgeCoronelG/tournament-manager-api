<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Los seeders de catálogo (datos reales, como roles) corren en cualquier
     * ambiente. Los de datos de prueba (usuarios falsos, etc.) solo corren
     * fuera de producción, para no ensuciar la base de datos real.
     */
    public function run(): void
    {
        $this->call([
            RolesSeeder::class,
        ]);

        if (! app()->isProduction()) {
            $this->call([
                DevelopmentSeeder::class,
            ]);
        }
    }
}
