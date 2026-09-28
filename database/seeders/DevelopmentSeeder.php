<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    /**
     * Datos de prueba para trabajar en local. Nunca se ejecuta en producción
     * (ver DatabaseSeeder). Seguro de correr varias veces: usa updateOrCreate.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['first_name' => 'Admin', 'last_name' => 'Demo', 'password' => 'password', 'photo_url' => 'https://via.placeholder.com/150']
        );
        $admin->roles()->sync(Role::query()->where('name', 'Super Administrador')->pluck('id'));

        User::factory(10)->create();
    }
}
