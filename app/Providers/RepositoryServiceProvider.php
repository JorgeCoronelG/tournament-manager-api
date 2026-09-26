<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public const INTERFACE_REPOSITORY_NAMESPACE = 'App\Contracts\Repositories\\';

    public const IMPLEMENT_REPOSITORY_NAMESPACE = 'App\Repositories\\';

    /** @var array<string, string> Interfaz => implementación (nombres relativos a Contracts y Repositories) */
    protected array $repositories = [
        //
    ];

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        foreach ($this->repositories as $interface => $implementation) {
            $this->app->bind(self::INTERFACE_REPOSITORY_NAMESPACE.$interface,
                self::IMPLEMENT_REPOSITORY_NAMESPACE.$implementation);
        }
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
