<?php

namespace App\Console\Commands;

use Symfony\Component\Console\Input\InputOption;

class MakeServiceCommand extends GeneratesContractedClass
{
    protected $name = 'make:service';

    protected $description = 'Crea un servicio con su interfaz y registra el binding';

    protected $type = 'Service';

    protected function suffix(): string
    {
        return 'Service';
    }

    protected function contractsNamespace(): string
    {
        return 'Contracts\Services';
    }

    protected function providerPath(): string
    {
        return 'Providers/ServiceLogicServiceProvider.php';
    }

    protected function providerProperty(): string
    {
        return 'services';
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('stubs/service.stub');
    }

    protected function contractStub(): string
    {
        return $this->resolveStubPath('stubs/service.interface.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Services';
    }

    protected function replacements(string $baseName): array
    {
        $repository = str_replace('/', '\\', $this->option('repository') ?: $baseName);
        $repositoryInterface = 'App\Contracts\Repositories\\'.$repository.'RepositoryInterface';

        if (! $this->files->exists($this->getPath($repositoryInterface))) {
            $this->components->warn("El repositorio [{$repository}Repository] no existe todavía. Créelo con make:repository o use --repository.");
        }

        return $this->modelReplacements($baseName, warnIfMissing: false) + [
            '{{ repositoryInterface }}' => $repositoryInterface,
            '{{ repositoryInterfaceName }}' => class_basename($repositoryInterface),
        ];
    }

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['repository', 'r', InputOption::VALUE_REQUIRED, 'Repositorio que usa el servicio (por defecto, el mismo nombre)'],
        ]);
    }
}
