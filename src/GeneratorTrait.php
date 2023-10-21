<?php

namespace NormanHuth\PackageInit;

use NormanHuth\Helpers\Str;
use NormanHuth\Helpers\Arr;

trait GeneratorTrait
{
    /**
     * Keep this keys in the `composer.json`.
     *
     * @var array|string[]
     */
    protected array $protectedJsonKeys = [
        'require',
        'require-dev',
        'autoload',
        'autoload-dev',
    ];

    /**
     * Replace all occurrences in this array on stubs.
     *
     * @var array
     */
    protected array $replacements;

    /**
     * @return void
     */
    protected function generate(): void
    {
        $this->setReplacements();
        $this->createComposerJson();
        $this->command->cwdDisk->makeDirectory('src');
        if (!$this->isLaravelPackage) {
            return;
        }
        $this->createLaravelServiceProvider();
        $this->createLaravelPackageFiles();
    }

    /**
     * @return void
     */
    protected function createLaravelPackageFiles(): void
    {
        if ($this->laravelPackageResources['Configuration']) {
            $this->command->cwdDisk->put(
                'config/' . explode('/', $this->name)[1] . '.php',
                "<?php\n\nreturn [];\n"
            );
        }

        if ($this->laravelPackageResources['Migrations']) {
            $this->command->cwdDisk->makeDirectory('database/migrations');
        }

        if ($this->laravelPackageResources['Routes']) {
            $this->command->cwdDisk->put(
                'routes/web.php',
                $this->getContents('stubs/routes/web.stub') . "\n"
            );
            $this->command->cwdDisk->put(
                'routes/api.php',
                $this->getContents('stubs/routes/api.stub') . "\n"
            );
        }

        if ($this->laravelPackageResources['Language Files']) {
            $this->command->cwdDisk->put(
                'lang/en.json',
                "{}\n"
            );
        }

        if ($this->laravelPackageResources['Views']) {
            $this->command->cwdDisk->makeDirectory('resources/views');
        }
    }

    /**
     * @return void
     */
    protected function createLaravelServiceProvider(): void
    {
        $contents = $this->getContents('stubs/PackageServiceProvider.stub');
        $resources = array_filter($this->laravelPackageResources);
        $fill = [
            'boot' => [],
            'register' => [],
        ];
        $methods = ['boot', 'register'];

        foreach (array_keys($resources) as $resource) {
            $resource = Str::slug($resource);
            foreach ($methods as $method) {
                $file = '/stubs/PackageServiceProvider/' . $method . '/' . $resource . '.stub';

                if (!file_exists(dirname(__DIR__) . $file)) {
                    continue;
                }

                $fill[$method][] = $this->getContents($file);
            }
        }

        foreach ($methods as $method) {
            $replace = count($fill[$method]) ? implode("\n\n", $fill[$method]) : "\t\t//";
            $contents = strReplace('{' . $method . '}', $replace, $contents);
        }

        $this->command->cwdDisk->put(
            'src/PackageServiceProvider.php',
            $contents
        );
    }

    /**
     * @return void
     */
    protected function createComposerJson(): void
    {
        $contents = $this->getContents('stubs/composer.json.stub', true);
        $data = json_decode($contents, true);

        if (!$this->isLaravelPackage) {
            unset($data['extra']);
        }

        $data = array_merge($data, [
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
            'license' => $this->license,
            'require' => $this->formatDependenciesForComposerJson(),
            'require-dev' => $this->formatDependenciesForComposerJson('require-dev'),
            'minimum-stability' => $this->minimumStability
        ]);

        $data = Arr::where($data, function (mixed $value, string $key) {
            return in_array($key, $this->protectedJsonKeys) || !empty($value);
        });

        $this->command->cwdDisk->put(
            'composer.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param string $key
     *
     * @return array
     */
    protected function formatDependenciesForComposerJson(string $key = 'require'): array
    {
        $data = Arr::mapWithKeys($this->requirements[$key], function (string $item) {
            $parts = explode(' ', $item);

            if (!str_contains($parts[0], '/') && !str_contains($parts[0], '-')) {
                $key = '0' . $parts[0];
            } elseif (!str_contains($parts[0], '/')) {
                $key = '1' . $parts[0];
            } else {
                $key = '2' . $parts[0];
            }

            return [$key => $parts[1]];
        });

        ksort($data);

        return Arr::mapWithKeys($data, function (string $value, string $key) {
            return [substr($key, 1) => $value];
        });
    }

    /**
     * @return void
     */
    protected function setReplacements(): void
    {
        $this->replacements = [
            '{vendor}' => explode('/', $this->name)[0],
            '{name}' => explode('/', $this->name)[1],
            '{namespace}' => $this->namespace,
        ];
    }

    /**
     * @param string $path
     * @param bool   $isJson
     *
     * @return string
     */
    protected function getContents(string $path, bool $isJson = false): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/' . trim($path, '/\\'));

        $search = array_keys($this->replacements);
        $replace = array_values($this->replacements);

        if ($isJson) {
            $replace = array_map(fn($item) => str_replace('\\', '\\\\', $item), $replace);
        }

        return str_replace(
            $search,
            $replace,
            rtrim($contents)
        );
    }
}
