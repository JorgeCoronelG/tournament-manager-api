<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Las selfies del registro se guardaban con la URL completa (http://localhost/storage/users/photos/x.webp),
// que deja de servir al cambiar APP_URL. Ahora se guarda solo la ruta del disco `public` y el modelo
// arma la URL al leerla; esto convierte las que ya estaban guardadas.
return new class extends Migration
{
    private const MARKER = '/storage/users/photos/';

    public function up(): void
    {
        DB::table('users')
            ->where('photo_url', 'like', '%'.self::MARKER.'%')
            ->orderBy('id')
            ->get(['id', 'photo_url'])
            ->each(function (object $user): void {
                $url = (string) $user->photo_url;
                $relative = 'users/photos/'.substr($url, strpos($url, self::MARKER) + strlen(self::MARKER));
                DB::table('users')->where('id', $user->id)->update(['photo_url' => $relative]);
            });
    }

    public function down(): void
    {
        $base = rtrim((string) config('filesystems.disks.public.url'), '/');

        DB::table('users')
            ->where('photo_url', 'like', 'users/photos/%')
            ->orderBy('id')
            ->get(['id', 'photo_url'])
            ->each(function (object $user) use ($base): void {
                DB::table('users')->where('id', $user->id)->update(['photo_url' => $base.'/'.$user->photo_url]);
            });
    }
};
