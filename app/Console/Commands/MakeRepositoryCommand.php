<?php

namespace App\Console\Commands;

class MakeRepositoryCommand extends GeneratesContractedClass
{
    protected $name = 'make:repository';

    protected $description = 'Crea un repositorio con su interfaz y registra el binding';

    protected $type = 'Repository';

    protected function suffix(): string
    {
        return 'Repository';
    }

    protected function contractsNamespace(): string
    {
        return 'Contracts\Repositories';
    }

    protected function providerPath(): string
    {
        return 'Providers/RepositoryServiceProvider.php';
    }

    protected function providerProperty(): string
    {
        return 'repositories';
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('stubs/repository.stub');
    }

    protected function contractStub(): string
    {
        return $this->resolveStubPath('stubs/repository.interface.stub');
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Repositories';
    }

    protected function replacements(string $baseName): array
    {
        return $this->modelReplacements($baseName, warnIfMissing: true);
    }
}
