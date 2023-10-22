<?php

namespace NormanHuth\PackageInit;

use Composer\Command\InitCommand;
use Composer\Factory;
use Composer\Filter\PlatformRequirementFilter\IgnoreAllPlatformRequirementFilter;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Package\BasePackage;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Util\Filesystem;
use InvalidArgumentException;
use NormanHuth\Helpers\Str;

trait ConfigurationTrait
{
    /**
     * @throws \Composer\Json\JsonValidationException
     * @return void
     */
    protected function configure(): void
    {
        $this->determineName();
        $this->determineDescription();
        $this->determineAuthors();
        $this->determineMinimumStability();
        $this->determinePackageType();
        $this->determineLicence();
        $this->determineIsLaravelPackage();
        $this->prepareResolveDependencies();
        $this->determinePhpDependencies();
        $this->determineLaravelDependencies();
        $this->determineLaravelPackageResources();
        $this->determineDependencies();

        $this->command->info('Package successful created');
    }

    /**
     * @return void
     */
    protected function determineLaravelPackageResources(): void
    {
        if (!$this->isLaravelPackage) {
            return;
        }
        foreach (array_keys($this->laravelPackageResources) as $resource) {
            $this->laravelPackageResources[$resource] = $this->command->confirm(
                'Add this package resource: ' . $resource,
                $this->laravelPackageResources[$resource]
            );
            if (
                !$this->laravelPackageResources[$resource] || empty($this->requirements['vendor']['illuminate']) ||
                empty($this->laravelResourceDependencies[$resource])
            ) {
                continue;
            }

            $dependency = 'illuminate/' . $this->laravelResourceDependencies[$resource] . ' ' .
                $this->requirements['vendor']['illuminate'];

            if (!in_array($dependency, $this->requirements['require'])) {
                $this->requirements['require'][] = $dependency;
            }
        }
    }

    /**
     * @return void
     */
    protected function determineLaravelDependencies(): void
    {
        if (!$this->isLaravelPackage) {
            return;
        }
        $package = 'illuminate/support';
        $latest = $this->getMajorVersion($this->findTheBestVersionAndNameForPackage($package)[1]);

        $choices = [
            '^' . ($latest - 2) . '.0|' . '^' . ($latest - 1) . '.0|^' . $latest . '.0',
            '^' . ($latest - 1) . '.0|' . '^' . $latest . '.0',
            '^' . $latest . '.0',
            'no'
        ];

        $version = $this->command->choice(
            'Define ’' . $package . '’ as (require) dependency?',
            $choices,
            $choices[0]
        );

        if ($version != 'no') {
            $this->requirements['vendor']['illuminate'] = $version;
            $this->requirements['require'][] = $package . ' ' . $version;
        }
    }

    /**
     * @return void
     */
    protected function determinePhpDependencies(): void
    {
        $versions = static::getDependenciesVersions();
        $latest = data_get($versions, 'php');
        if ($latest) {
            $phpVersions[] = $this->getMajorVersion($latest);
        }
        $phpVersions[] = PHP_MAJOR_VERSION;
        $phpVersions = array_unique($phpVersions);
        sort($phpVersions);

        $php = implode('|', array_map(fn($version) => '^' . $version . '.0', $phpVersions));

        if ($this->command->confirm('Define PHP ’' . $php . '’ as (require) dependency?', true)) {
            $this->requirements['require'][] = 'php ' . $php;
        }
    }

    /**
     * @return void
     */
    protected function determineIsLaravelPackage(): void
    {
        $this->isLaravelPackage = $this->command->confirm('Create a Laravel package?', $this->isLaravelPackage);
    }

    /**
     * @return void
     */
    protected function determineLicence(): void
    {
        $license = null;
        if (!empty($_SERVER['COMPOSER_DEFAULT_LICENSE'])) {
            $license = $_SERVER['COMPOSER_DEFAULT_LICENSE'];
        }

        $this->license = $this->command->ask('License', $license);
    }

    /**
     * @return void
     */
    protected function determinePackageType(): void
    {
        $this->type = $this->command->ask('Package Type');

        if ($this->type && !in_array($this->type, $this->validTypes)) {
            $this->command->warn(
                'Warning: The package type ’' . $this->type .
                '’ will need to provide an installer capable of installing packages of that type.'
            );
            $this->command->warn(
                'Out of the box, Composer supports four types: ' .
                implode(', ', $this->validTypes)
            );
        }
    }

    /**
     * @return void
     */
    protected function determineAuthors(): void
    {
        $name = null;
        if ($this->firstAuthor) {
            if (!empty($_SERVER['COMPOSER_DEFAULT_AUTHOR'])) {
                $name = $_SERVER['COMPOSER_DEFAULT_AUTHOR'];
            } elseif (!empty($this->git['user.name'])) {
                $name = $this->git['user.name'];
            }
        }

        $method = $name && $this->firstAuthor ? 'askSkippable' : 'ask';
        $author = $this->command->{$method}('Add author ' . (count($this->authors) + 1) . ' (name)', $name);

        if ($author) {
            $this->authors[$author]['name'] = $author;

            $this->determineAuthorEmail($author);
            $this->determineAuthorHomepage($author);

            $this->firstAuthor = false;

            $this->determineAuthors();
        }
    }

    /**
     * @param string $author
     *
     * @return void
     */
    protected function determineAuthorHomepage(string $author): void
    {
        $homepage = null;
        if (!empty($this->authors[$author]['email']) && str_contains($this->authors[$author]['email'], '@')) {
            $provider = explode('@', $this->authors[$author]['email'])[1];
            if (!in_array($provider, $this->getEmailProviders())) {
                $homepage = 'https://' . $provider;
            }
        }
        if (!$homepage && !empty($_SERVER['COMPOSER_DEFAULT_HOMEPAGE'])) {
            $homepage = $_SERVER['COMPOSER_DEFAULT_HOMEPAGE'];
        }

        $method = $homepage ? 'askSkippable' : 'ask';
        $homepage = $this->command->{$method}('Homepage of ' . $author, $homepage);

        if ($homepage) {
            if (!Str::isUrl($homepage)) {
                $this->command->error('You must enter a valid url.');
                $this->determineAuthorHomepage($author);
                return;
            }

            $this->authors[$author]['homepage'] = $homepage;
        }
    }

    /**
     * @return array|mixed
     */
    protected function getEmailProviders(): mixed
    {
        if (!count($this->emailProviders)) {
            $this->emailProviders = json_decode(
                file_get_contents(dirname(__DIR__) . '/storage/email-providers.json'),
                true
            );
        }

        return $this->emailProviders;
    }

    /**
     * @param string $author
     *
     * @return void
     */
    protected function determineAuthorEmail(string $author): void
    {
        $email = null;
        if ($this->firstAuthor) {
            if (!empty($_SERVER['COMPOSER_DEFAULT_EMAIL'])) {
                $email = $_SERVER['COMPOSER_DEFAULT_EMAIL'];
            } elseif (!empty($this->git['user.email'])) {
                $email = $this->git['user.email'];
            }
        }

        $method = $this->firstAuthor ? 'askSkippable' : 'ask';
        $email = $this->command->{$method}('Email address for ' . $author, $email);

        if ($email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->command->error('You must enter a valid email address.');
                $this->determineAuthorEmail($author);
                return;
            }

            $this->authors[$author]['email'] = $email;
        }
    }

    /**
     * @return void
     */
    protected function determineMinimumStability(): void
    {
        $this->minimumStability = $this->command->ask('Minimum Stability');
        if ($this->minimumStability) {
            $this->minimumStability = preg_replace('/\s+/', '', $this->minimumStability);
            $stabilities = explode(',', $this->minimumStability);
            $allowedStabilities = array_keys(BasePackage::$stabilities);
            foreach ($stabilities as $stability) {
                if (!in_array($stability, $allowedStabilities)) {
                    $this->command->error(
                        'Invalid minimum stability' . $stability . '. Must be empty or one of: ' .
                        implode(', ', $allowedStabilities)
                    );
                    $this->determineMinimumStability();
                }
            }
            $this->minimumStability = str_replace(',', ', ', $this->minimumStability);
        }

        $this->preferredStability = $this->minimumStability ?: $this->preferredStability;
    }

    /**
     * @return void
     */
    protected function determineDescription(): void
    {
        $this->description = $this->command->ask('Description');
    }

    /**
     * @return void
     */
    protected function prepareResolveDependencies(): void
    {
        $this->platformRepository = null;
        foreach ($this->getRepos()->getRepositories() as $candidateRepo) {
            if ($candidateRepo instanceof PlatformRepository) {
                $this->platformRepository = $candidateRepo;
                break;
            }
        }
    }

    /**
     * @throws \Composer\Json\JsonValidationException
     */
    protected function determineDependencies(): void
    {
        foreach (['require', 'require-dev'] as $key) {
            $requirements = $this->requirements[$key];
            if (
                !count($requirements) &&
                !$this->command->confirm(
                    'Would you like to define your dependencies (' . $key . ') interactively',
                    true
                )
            ) {
                continue;
            }
            $this->requirements[$key] = $this->determineTheRequirements($this->requirements[$key]);
        }
    }

    /**
     * Based on \Composer\Composer.
     *
     * @noinspection DuplicatedCode
     * @throws \Composer\Json\JsonValidationException
     * @author       Nils Adermann <naderman@naderman.de>
     * @author       Jordi Boggiano <j.boggiano@seld.be>
     * @return array<string>
     */
    protected function determineTheRequirements(array $requires = []): array
    {
        $composer = $this->tryComposer();
        $installedRepo = $composer?->getRepositoryManager()->getLocalRepository();
        $existingPackages = [];
        if (null !== $installedRepo) {
            foreach ($installedRepo->getPackages() as $package) {
                $existingPackages[] = $package->getName();
            }
        }
        unset($composer, $installedRepo);

        while ($package = $this->command->ask('Search for a package:')) {
            $matches = $this->getRepos()->search($package);

            if (count($matches)) {
                // Remove existing packages from search results.
                foreach ($matches as $position => $foundPackage) {
                    if (in_array($foundPackage['name'], $existingPackages, true)) {
                        unset($matches[$position]);
                    }
                }
                $matches = array_values($matches);

                $exactMatch = false;
                foreach ($matches as $match) {
                    if ($match['name'] === $package) {
                        $exactMatch = true;
                        break;
                    }
                }

                // no match, prompt which to pick
                if (!$exactMatch) {
                    $providers = $this->getRepos()->getProviders($package);
                    if (count($providers) > 0) {
                        array_unshift($matches, ['name' => $package, 'description' => '']);
                    }

                    $choices = [];
                    foreach ($matches as $foundPackage) {
                        $abandoned = '';
                        if (isset($foundPackage['abandoned'])) {
                            if (is_string($foundPackage['abandoned'])) {
                                $replacement = sprintf('Use %s instead', $foundPackage['abandoned']);
                            } else {
                                $replacement = 'No replacement was suggested';
                            }
                            $abandoned = sprintf('<fg=yellow;options=bold>Abandoned. %s.</>', $replacement);
                        }

                        $choices[] = trim($foundPackage['name'] . ' ' . $abandoned);
                    }

                    $question = sprintf(
                        "<comment>Found <info>%s</info> packages matching</comment> <info>%s</info>",
                        count($matches),
                        $package
                    );

                    $package = $this->command->choice($question, $choices, null, 3);
                    if (!$package) {
                        continue;
                    }
                    $package = explode(' ', $package)[0];
                }

                if (false !== $package && !str_contains($package, ' ')) {
                    $constraint = $this->command->ask(
                        'Enter the version constraint to require ' .
                        '(or leave empty to determine a version automatically):'
                    );

                    if (!$constraint) {
                        $vendorParts = explode('/', $package);
                        if (
                            !empty($vendorParts[1]) && in_array($vendorParts[0], $this->vendorToAdoptVersions) &&
                            !empty($this->requirements['vendor'][$vendorParts[0]])
                        ) {
                            $constraint = $this->requirements['vendor'][$vendorParts[0]];
                        }

                        if (!$constraint) {
                            [, $constraint] = $this->findTheBestVersionAndNameForPackage($package);
                        }

                        $this->command->line(sprintf(
                            'Using version <info>%s</info> for <info>%s</info>',
                            $constraint,
                            $package
                        ));
                    }

                    $package .= ' ' . $constraint;
                }
                if (false !== $package) {
                    $requires[] = $package;
                    $vendorParts = explode('/', $package);
                    $versionParts = explode(' ', $package);
                    if (!empty($vendorParts[1]) && !empty($versionParts[1])) {
                        $this->requirements['vendor'][$vendorParts[0]] = $versionParts[1];
                    }
                    $existingPackages[] = explode(' ', $package)[0];
                }
            }
        }

        return $requires;
    }

    /**
     * Based on \Composer\Composer.
     *
     * @param string $name
     * @param bool   $fixed
     *
     * @author       Jordi Boggiano <j.boggiano@seld.be>
     * @author       Nils Adermann <naderman@naderman.de>
     * @return array|string[]
     */
    protected function findTheBestVersionAndNameForPackage(string $name, bool $fixed = false): array
    {
        if ($this->ignorePlatformReqs) {
            $platformRequirementFilter = $this->getThePlatformRequirementFilter();
        } else {
            $platformRequirementFilter = PlatformRequirementFilterFactory::ignoreNothing();
        }

        // find the latest version allowed in this repo set
        $repoSet = $this->getTheRepositorySet();
        $versionSelector = new VersionSelector($repoSet, $this->platformRepository);
        $effectiveMinimumStability = $this->getTheMinimumStability();

        $package = $versionSelector->findBestCandidate(
            $name,
            null,
            $this->preferredStability,
            $platformRequirementFilter,
            0,
            $this->getIO()
        );

        if (false === $package) {
            // platform packages can not be found in the pool in versions other than the local platform's has
            // so if platform reqs are ignored we just take the user's word for it
            if ($platformRequirementFilter->isIgnored($name)) {
                return [$name, '*'];
            }

            // Check if it is a virtual package provided by others
            $providers = $repoSet->getProviders($name);
            if (count($providers) > 0) {
                // Todo
                $constraint = $this->command->askAndValidate(
                    'Package ' . $name . 'does not exist but is provided by ' . count($providers) .
                    ' packages. Which version constraint would you like to use?',
                    function ($value) {
                        $this->versionParser->parseConstraints($value);

                        return $value;
                    },
                );

                if ($constraint === false) {
                    $constraint = '*';
                }

                return [$name, $constraint];
            }

            // Check whether the package requirements were the problem
            if (
                !($platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter) &&
                false !== ($candidate = $versionSelector->findBestCandidate(
                    $name,
                    null,
                    $this->preferredStability,
                    PlatformRequirementFilterFactory::ignoreAll()
                ))
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Package %s has requirements incompatible with your PHP version, ' .
                    'PHP extensions and Composer version' .
                    $this->getPlatformExceptionDetails($candidate, $this->platformRepository),
                    $name
                ));
            }

            // Check whether the minimum stability was the problem but the package exists
            if (
                false !== ($package = $versionSelector->findBestCandidate(
                    $name,
                    null,
                    $this->preferredStability,
                    $platformRequirementFilter,
                    RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES
                ))
            ) {
                // we must first verify if a valid package would be found in a lower priority repository
                if (
                    false !== ($allReposPackage = $versionSelector->findBestCandidate(
                        $name,
                        null,
                        $this->preferredStability,
                        $platformRequirementFilter,
                        RepositorySet::ALLOW_SHADOWED_REPOSITORIES
                    ))
                ) {
                    throw new InvalidArgumentException(
                        'Package ' . $name . ' exists in ' . $allReposPackage->getRepository()->getRepoName() .
                        ' and ' . $package->getRepository()->getRepoName() .
                        ' which has a higher repository priority. The packages from the higher priority repository ' .
                        'do not match your minimum-stability and are therefore not installable. ' .
                        'That repository is canonical so the lower priority repo\'s packages are not installable. ' .
                        'See https://getcomposer.org/repoprio for details and assistance.'
                    );
                }

                throw new InvalidArgumentException(sprintf(
                    'Could not find a version of package %s matching your minimum-stability (%s). ' .
                    'Require it with an explicit version constraint allowing its desired stability.',
                    $name,
                    $effectiveMinimumStability
                ));
            }
            // Check whether the PHP version was the problem for all versions
            if (
                !$platformRequirementFilter instanceof IgnoreAllPlatformRequirementFilter &&
                false !== ($candidate = $versionSelector->findBestCandidate(
                    $name,
                    null,
                    $this->preferredStability,
                    PlatformRequirementFilterFactory::ignoreAll(),
                    RepositorySet::ALLOW_UNACCEPTABLE_STABILITIES
                )
                )
            ) {
                $additional = '';
                if (
                    false === $versionSelector->findBestCandidate(
                        $name,
                        null,
                        $this->preferredStability,
                        PlatformRequirementFilterFactory::ignoreAll()
                    )
                ) {
                    $additional = PHP_EOL . PHP_EOL . 'Additionally, the package was only found with a stability of "' .
                        $candidate->getStability() . '" while your minimum stability is "' .
                        $effectiveMinimumStability . '".';
                }

                throw new InvalidArgumentException(sprintf(
                    'Could not find package %s in any version matching your PHP version, ' .
                    'PHP extensions and Composer version' .
                    $this->getPlatformExceptionDetails($candidate, $this->platformRepository) . '%s',
                    $name,
                    $additional
                ));
            }

            // Check for similar names/typos
            $similar = $this->findSimilar($name);
            if (count($similar) > 0) {
                if (in_array($name, $similar, true)) {
                    throw new InvalidArgumentException(sprintf(
                        "Could not find package %s. It was however found via repository search, " .
                        'which indicates a consistency issue with the repository.',
                        $name
                    ));
                }

                return $this->findTheBestVersionAndNameForPackage($similar[0], $fixed);
            }

            throw new InvalidArgumentException(sprintf(
                'Could not find a matching version of package %s. Check the package spelling, ' .
                'your version constraint and that the package is available in a stability which matches your ' .
                'minimum-stability (%s).',
                $name,
                $effectiveMinimumStability
            ));
        }

        return [
            $package->getPrettyName(),
            $fixed ? $package->getPrettyVersion() : $versionSelector->findRecommendedRequireVersion($package),
        ];
    }

    /**
     * @return \Composer\Repository\RepositorySet
     */
    protected function getTheRepositorySet(): RepositorySet
    {
        $key = $this->minimumStability ?? 'default';

        if (!isset($this->theRepositorySets[$key])) {
            $this->theRepositorySets[$key] = $repositorySet = new RepositorySet($minimumStability ??
                $this->getTheMinimumStability());
            $repositorySet->addRepository($this->getRepos());
        }

        return $this->theRepositorySets[$key];
    }

    /**
     * @return string
     */
    protected function getTheMinimumStability(): string
    {
        if ($this->stability) {
            return $this->versionParser::normalizeStability($this->stability);
        }

        $file = Factory::getComposerFile();
        if (
            is_file($file) && Filesystem::isReadable($file) &&
            is_array($composer = json_decode((string) file_get_contents($file), true))
        ) {
            if (isset($composer['minimum-stability'])) {
                return VersionParser::normalizeStability($composer['minimum-stability']);
            }
        }

        return 'stable';
    }

    /**
     * Determine the name of the package.
     * (Based on \Composer\Composer)
     *
     * @author       Nils Adermann <naderman@naderman.de>
     * @author       Jordi Boggiano <j.boggiano@seld.be>
     * @noinspection DuplicatedCode
     * @return void
     */
    protected function determineName(): void
    {
        $name = basename($this->cwd);
        $name = Preg::replace(
            '{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}',
            '\\1\\3-\\2\\4',
            $name
        );
        $name = strtolower($name);
        if (!empty($_SERVER['COMPOSER_DEFAULT_VENDOR'])) {
            $name = $_SERVER['COMPOSER_DEFAULT_VENDOR'] . '/' . $name;
        } elseif (isset($git['github.user'])) {
            $name = $git['github.user'] . '/' . $name;
        } elseif (!empty($_SERVER['USERNAME'])) {
            $name = $_SERVER['USERNAME'] . '/' . $name;
        } elseif (!empty($_SERVER['USER'])) {
            $name = $_SERVER['USER'] . '/' . $name;
        } elseif (get_current_user()) {
            $name = get_current_user() . '/' . $name;
        } else {
            $name .= '/' . $name;
        }
        $name = Str::lower($name);

        $this->name = $this->command->ask(
            'Package name (<vendor>/<name>)',
            $name
        );

        if (!$this->name || !Preg::isMatch('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}D', $this->name)) {
            $this->command->error('The package name `' . $this->name . '` is invalid.');
            $this->command->error('It should be lowercase and have a vendor name, a forward slash,');
            $this->command->error('and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+');

            $this->determineName();
        }

        $this->namespace = (new InitCommand())->namespaceFromPackageName($this->name);
    }
}
