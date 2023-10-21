<?php

namespace NormanHuth\PackageInit;

use Composer\Command\PackageDiscoveryTrait;
use Composer\Composer;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Pcre\Preg;
use Composer\Repository\PlatformRepository;
use NormanHuth\Lura\LuraCommand;
use NormanHuth\Lura\LuraInstaller;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Console\Application;
use Composer\Package\Version\VersionParser;
use Composer\Config;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Command extends LuraInstaller
{
    use ConfigurationTrait;
    use GeneratorTrait;
    use PackageDiscoveryTrait;

    /**
     * @var \Composer\IO\IOInterface|null
     */
    protected ?IOInterface $io = null;

    /**
     * Minimum stability of package. Defaults to `stable`.
     *
     * @var string|null
     */
    protected ?string $stability = 'stable';

    /**
     * The requirements.
     *
     * @var array|array[]
     */
    protected array $requirements = [
        'require' => [],
        'require-dev' => [],
        'vendor' => [],
    ];

    /**
     * Adopt version if a vendor package already exist on automatically determination.
     *
     * @var array|string[]
     */
    protected array $vendorToAdoptVersions = [
        'illuminate',
        'symfony',
    ];

    /**
     * Author name of the package.
     *
     * @var array
     */
    protected array $authors = [];

    /**
     * Don’t change. This is a work property. 🚫
     *
     * @var bool
     */
    protected bool $firstAuthor = true;

    /**
     * Don’t change. This is a work property. 🚫
     *
     * @var array
     */
    protected array $emailProviders = [];

    /**
     * @var array<string, string>
     */
    protected ?array $git = null;

    /**
     * The package license.
     *
     * @var string|null
     */
    protected ?string $license = null;

    /**
     * @var \Composer\Repository\RepositorySet[]
     */
    protected array $theRepositorySets;

    /**
     * Determine if create a package for Laravel Framework.
     *
     * @var bool
     */
    protected bool $isLaravelPackage = true;

    /**
     * Determine Laravel package resources.
     *
     * @var array<string, bool>
     */
    protected array $laravelPackageResources = [
        'Configuration' => true,
        'Migrations' => true,
        'Routes' => true,
        'Language Files' => true,
        'Views' => false,
        //'View Components' => false,
        //'"About" Artisan Command' => true, # Todo
    ];

    /**
     * @var array<string, string>
     */
    protected array $laravelResourceDependencies = [
        'Configuration' => 'config',
        'Migrations' => 'database',
        'Routes' => 'routing',
        'Language Files' => 'translation',
        'Views' => 'view',
        'View Components' => 'view',
    ];

    /**
     * The Package namespace.
     *
     * @var string
     */
    protected string $namespace;

    /**
     * The PlatformRepository instance.
     *
     * @var \Composer\Repository\PlatformRepository|null
     */
    protected ?PlatformRepository $platformRepository;

    /**
     * The Command instance.
     *
     * @var \NormanHuth\Lura\LuraCommand
     */
    protected LuraCommand $command;

    /**
     * Minimum stability of package. Defaults to `stable`.
     *
     * @var string|null
     */
    protected ?string $minimumStability = null;

    /**
     * Preferred stability of package. Defaults to `stable`.
     *
     * @var string
     */
    protected string $preferredStability = 'stable';

    /**
     * Ignore all platform requirements.
     *
     * @var mixed
     */
    protected mixed $ignorePlatformReqs = false;

    /**
     * The description of the package.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * Type of package.
     *
     * @var string|null
     */
    protected ?string $type = null;

    /**
     * Out of the box supported types.
     *
     * @see https://getcomposer.org/doc/04-schema.md#type
     * @var array|string[]
     */
    protected array $validTypes = [
        'library',
        'project',
        'metapackage',
        'composer-plugin',
    ];

    /**
     * The current working directory.
     *
     * @var string
     */
    protected string $cwd;

    /**
     * @var \Composer\Package\Version\VersionParser
     */
    protected VersionParser $versionParser;

    /**
     * The Config instance.
     *
     * @var \Composer\Config
     */
    protected Config $config;

    /**
     * Name of the package.
     *
     * @var string
     */
    protected string $name;

    /**
     * The Composer instance.
     *
     * @var \Composer\Composer|null
     */
    protected ?Composer $composer = null;

    /**
     * Execute the installer console command.
     *
     * @param mixed|\NormanHuth\Lura\LuraCommand $command
     *
     * @throws \Composer\Json\JsonValidationException
     */
    public function runLura(mixed $command): void
    {
        $this->initialize($command);
        $this->configure();
        $this->generate();
    }

    /**
     * Initialize Lura Installer.
     *
     * @param \NormanHuth\Lura\LuraCommand $command
     *
     * @return void
     */
    protected function initialize(LuraCommand $command): void
    {
        $this->command = $command;
        $this->cwd = realpath('.');
        $this->versionParser = new VersionParser();
        $this->setGit();
    }

    /**
     * @return void
     */
    protected function setGit(): void
    {
        $this->git = [];
        $finder = new ExecutableFinder();
        $gitBin = $finder->find('git');

        $cmd = new Process([$gitBin, 'config', '-l']);
        $cmd->run();

        if ($cmd->isSuccessful()) {
            Preg::matchAllStrictGroups('{^([^=]+)=(.*)$}m', $cmd->getOutput(), $matches);
            foreach ($matches[1] as $key => $match) {
                $this->git[$match] = $matches[2][$key];
            }
        }
    }

    /**
     * @return \Composer\IO\IOInterface|\Composer\IO\NullIO
     */
    public function getIO(): IOInterface|NullIO
    {
        if (null === $this->io) {
            $application = $this->command->getApplication();
            if ($application instanceof Application) {
                $this->io = $application->getIO();
            } else {
                $this->io = new NullIO();
            }
        }

        return $this->io;
    }


    /**
     * Retrieves the default Composer\Composer instance or null
     * Use this instead of getComposer(false)
     *
     * @param bool|null $disablePlugins If null, reads --no-plugins as default
     * @param bool|null $disableScripts If null, reads --no-scripts as default
     *
     * @throws \Composer\Json\JsonValidationException
     */
    public function tryComposer(?bool $disablePlugins = null, ?bool $disableScripts = null): ?Composer
    {
        if ($this->composer === null) {
            $application = $this->command->getApplication();
            if ($application instanceof Application) {
                $this->composer = $application->getComposer(false, $disablePlugins, $disableScripts);
            }
        }

        return $this->composer;
    }

    /**
     * @return \Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface
     */
    protected function getThePlatformRequirementFilter(): PlatformRequirementFilterInterface
    {
        if (!$this->ignorePlatformReqs) {
            return PlatformRequirementFilterFactory::ignoreAll();
        }

        if (is_countable($this->ignorePlatformReqs) && count($this->ignorePlatformReqs) > 0) {
            return PlatformRequirementFilterFactory::fromBoolOrList($this->ignorePlatformReqs);
        }

        return PlatformRequirementFilterFactory::ignoreNothing();
    }
}
