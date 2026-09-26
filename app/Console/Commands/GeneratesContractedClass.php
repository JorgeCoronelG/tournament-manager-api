<?php

namespace App\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Base de make:repository y make:service. Crea la interfaz (en Contracts) y la
 * implementación, y registra el binding en el provider correspondiente.
 */
abstract class GeneratesContractedClass extends GeneratorCommand
{
    /** Sufijo del nombre de la clase: "Repository" o "Service". */
    abstract protected function suffix(): string;

    /** Namespace de la interfaz relativo a App: "Contracts\Repositories". */
    abstract protected function contractsNamespace(): string;

    /** Ruta del provider relativa a app/: "Providers/RepositoryServiceProvider.php". */
    abstract protected function providerPath(): string;

    /** Nombre de la propiedad del provider con los bindings: "repositories". */
    abstract protected function providerProperty(): string;

    abstract protected function contractStub(): string;

    /**
     * Valores extra para los stubs.
     *
     * @return array<string, string>
     */
    abstract protected function replacements(string $baseName): array;

    protected function getNameInput(): string
    {
        $name = trim($this->argument('name'));

        return Str::endsWith($name, $this->suffix()) ? $name : $name.$this->suffix();
    }

    // GeneratorCommand documenta bool|null, pero Laravel convierte false en código de salida 0.
    // Se devuelve un código real para que scripts y CI detecten el error.
    // @phpstan-ignore method.childReturnType
    public function handle(): int
    {
        $rootNamespace = trim($this->rootNamespace(), '\\');
        $classNamespace = $this->getDefaultNamespace($rootNamespace);
        $class = $this->qualifyClass($this->getNameInput());

        if (! Str::startsWith($class, $classNamespace.'\\')) {
            $this->components->error("El nombre debe estar dentro de $classNamespace.");

            return self::FAILURE;
        }

        $relative = Str::after($class, $classNamespace.'\\');
        $interface = $rootNamespace.'\\'.$this->contractsNamespace().'\\'.$relative.'Interface';

        foreach ([$class, $interface] as $fqcn) {
            if (! $this->option('force') && $this->files->exists($this->getPath($fqcn))) {
                $this->components->error("$fqcn ya existe. Use --force para sobrescribirlo.");

                return self::FAILURE;
            }
        }

        $baseName = Str::beforeLast($relative, $this->suffix());
        $values = $this->replacements($baseName) + ['{{ interface }}' => $interface];

        $this->writeClass($interface, $this->contractStub(), $values);
        $this->writeClass($class, $this->getStub(), $values);
        $this->registerBinding($relative.'Interface', $relative);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeClass(string $fqcn, string $stub, array $values): void
    {
        $path = $this->getPath($fqcn);
        $this->makeDirectory($path);

        $contents = strtr($this->files->get($stub), $values + [
            '{{ namespace }}' => Str::beforeLast($fqcn, '\\'),
            '{{ class }}' => class_basename($fqcn),
            '{{ interfaceName }}' => class_basename($values['{{ interface }}']),
        ]);

        $this->files->put($path, $contents);
        $this->components->info(sprintf('Se creó [%s].', Str::after($path, base_path().DIRECTORY_SEPARATOR)));
    }

    /**
     * Agrega "Interfaz => Implementación" al array del provider.
     */
    private function registerBinding(string $key, string $value): void
    {
        $path = $this->laravel->path($this->providerPath());
        $pattern = '/(protected array \$'.$this->providerProperty().' = \[)(.*?)(\n    \];)/s';

        if (! $this->files->exists($path) || ! preg_match($pattern, $this->files->get($path), $match)) {
            $this->components->warn("No se pudo registrar el binding. Agregue '$key' => '$value' en {$this->providerPath()}.");

            return;
        }

        if (str_contains($match[2], "'$key'")) {
            return;
        }

        $lines = array_values(array_filter(
            explode("\n", $match[2]),
            fn (string $line) => trim($line) !== '' && trim($line) !== '//'
        ));

        if ($lines !== [] && ! str_ends_with(rtrim(end($lines)), ',')) {
            $lines[count($lines) - 1] = rtrim(end($lines)).',';
        }

        $lines[] = "        '$key' => '$value',";

        $this->files->put(
            $path,
            str_replace($match[0], $match[1]."\n".implode("\n", $lines).$match[3], $this->files->get($path))
        );
        $this->components->info("Binding registrado en {$this->providerPath()}.");
    }

    protected function resolveStubPath(string $stub): string
    {
        return $this->laravel->basePath(trim($stub, '/'));
    }

    /**
     * Valores de los stubs relacionados con el modelo (--model, por defecto el mismo nombre en App\Models).
     *
     * @return array<string, string>
     */
    protected function modelReplacements(string $baseName, bool $warnIfMissing): array
    {
        $model = $this->option('model');
        $model = is_string($model) && $model !== '' ? $model : $baseName;
        $model = Str::startsWith($model, 'App\\') ? $model : 'App\Models\\'.str_replace('/', '\\', $model);

        if ($warnIfMissing && ! class_exists($model)) {
            $this->components->warn("El modelo [$model] no existe todavía. Créelo o use --model.");
        }

        return [
            '{{ model }}' => $model,
            '{{ modelName }}' => class_basename($model),
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Sobrescribe los archivos si ya existen'],
            ['model', 'm', InputOption::VALUE_REQUIRED, 'Modelo (por defecto, el mismo nombre en App\Models)'],
        ];
    }
}
