<?php

namespace NormanHuth\PackageInit;

use NormanHuth\Helpers\Str;
use NormanHuth\Helpers\Arr;

trait GeneratorTrait
{
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
        $this->addLicenseFile();
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
    protected function addLicenseFile(): void
    {
        if (!$this->license) {
            return;
        }

        $license = collect(json_decode(
            file_get_contents(dirname(__DIR__) . '/storage/licenses.json'),
            true
        ))->firstWhere('key', Str::lower(trim($this->license)));

        if ($license) {
            $body = data_get($license, 'body');
            if (!$body) {
                return;
            }
            $fullname = data_get(array_values($this->authors), '0.name');
            if (!$fullname) {
                $fullname = explode('\\', $this->namespace)[0];
            }

            $body = str_replace(
                ['[year]', '[fullname]'],
                [date('Y'), $fullname],
                $body
            );

            $this->command->cwdDisk->put('license', $body);
        }
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
            $this->command->cwdDisk->makeDirectory('src/Http/Controllers');
            $this->command->cwdDisk->makeDirectory('src/Http/Middleware');
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
        $this->command->cwdDisk->makeDirectory('src/Console/Commands');
    }

    /**
     * @return void
     */
    protected function createLaravelServiceProvider(): void
    {
        $contents = $this->getContents('stubs/PackageServiceProvider.stub');
        $resources = array_merge($this->laravelPackageResources, [
            'Base' => true,
        ]);
        $resources = array_filter($resources);
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
            'authors' => array_values($this->authors),
            'minimum-stability' => $this->minimumStability
        ]);

        $this->command->cwdDisk->put(
            'composer.json',
            json_encode(array_filter($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
            '{organisation}' => explode('\\', $this->namespace)[0]
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
