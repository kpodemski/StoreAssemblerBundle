<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Command;

use Composer\InstalledVersions;
use Sylius\StoreAssemblerBundle\Plugin\PluginCatalog;
use Sylius\StoreAssemblerBundle\Plugin\PluginDefinition;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\PluginWorkflowOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;

#[AsCommand(
    name: 'sylius:plugins:install',
    description: 'Browse the plugin catalog and install selected plugins interactively',
)]
final class InstallInteractiveCommand extends Command
{
    public function __construct(
        private readonly PluginCatalog $catalog,
        private readonly PluginWorkflowOrchestrator $workflow,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::OPTIONAL, 'Plugin package name, e.g. sylius/cms-plugin')
            ->addOption('plugin-version', null, InputOption::VALUE_REQUIRED, 'Plugin version to install (defaults to the latest available)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter interactive selection by type (community or commercial)')
            ->addOption('no-prepare', null, InputOption::VALUE_NONE, 'Skip Composer installation step')
            ->addOption('no-configure', null, InputOption::VALUE_NONE, 'Skip manifest configuration step')
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'Target workflow platform (optional)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $selected = [];
        $package = $input->getArgument('package');
        $version = $input->getOption('plugin-version');

        if ($package !== null) {
            $definition = $this->catalog->find((string) $package, $version === null ? null : (string) $version);
            if ($definition === null) {
                $io->error(sprintf(
                    'Plugin "%s"%s was not found in the catalog.',
                    $package,
                    $version ? sprintf(' (version %s)', $version) : ''
                ));

                return Command::FAILURE;
            }

            $selected[] = $definition;
        } else {
            try {
                $selected = $this->promptForPlugins($io, $input->getOption('type'));
            } catch (\InvalidArgumentException $exception) {
                $io->error($exception->getMessage());

                return Command::INVALID;
            }

            if ($selected === []) {
                $io->warning('No plugins selected.');

                return Command::SUCCESS;
            }
        }

        $pluginMap = [];
        foreach ($selected as $definition) {
            $packageName = $definition->package;
            if (isset($pluginMap[$packageName]) && $pluginMap[$packageName] !== $definition->version) {
                $io->error(sprintf(
                    'Plugin "%s" was selected with conflicting versions (%s vs %s). Please choose a single version.',
                    $packageName,
                    $pluginMap[$packageName],
                    $definition->version
                ));

                return Command::INVALID;
            }

            $pluginMap[$packageName] = $definition->version;
        }

        $io->section('Selected plugins');
        $io->listing(array_map(
            static fn (PluginDefinition $definition) => sprintf(
                '%s@%s (%s)',
                $definition->package,
                $definition->version,
                $definition->isCommercial() ? 'commercial' : 'community'
            ),
            $selected
        ));

        if ($input->isInteractive() && !$io->confirm('Proceed with installation?', true)) {
            $io->warning('Installation cancelled by user.');

            return Command::SUCCESS;
        }

        try {
            if (!$input->getOption('no-prepare')) {
                /** @var string|null $platform */
                $platform = $input->getOption('platform');
                $this->workflow->prepare($io, $output, $pluginMap, $platform !== null ? (string) $platform : null);
            } else {
                $io->note('Skipping Composer installation step (--no-prepare).');
            }

            if (!$input->getOption('no-configure')) {
                /** @var string|null $platform */
                $platform = $input->getOption('platform');
                $this->workflow->install($io, $output, $pluginMap, $platform !== null ? (string) $platform : null);
            } else {
                $io->note('Skipping manifest configuration step (--no-configure).');
            }

            // Post-install maintenance: run DB migrations and reload cache
            $this->runMigrationsAndReloadCache($io, $output);
        } catch (ProcessFailedException $exception) {
            $io->error(trim($exception->getMessage()));

            return Command::FAILURE;
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param null|string $typeFilter
     *
     * @return list<PluginDefinition>
     */
    private function promptForPlugins(SymfonyStyle $io, ?string $typeFilter): array
    {
        $definitions = $this->catalog->all();
        if ($definitions === []) {
            $io->warning('Plugin catalog is empty.');

            return [];
        }

        $typeFilter = $typeFilter === null ? '' : trim((string) $typeFilter);
        if ($typeFilter !== '') {
            $normalized = strtolower($typeFilter);
            if (!in_array($normalized, ['commercial', 'community'], true)) {
                throw new \InvalidArgumentException('Invalid type filter, use "community" or "commercial".');
            }

            $definitions = array_filter(
                $definitions,
                static fn (PluginDefinition $definition) => $definition->type() === $normalized,
            );
        }

        if ($definitions === []) {
            $io->warning('No plugins match the requested filters.');

            return [];
        }

        usort(
            $definitions,
            fn (PluginDefinition $a, PluginDefinition $b): int => $this->sortPlugins($a, $b)
        );

        // Build and render a neat table for selection
        $rows = [];
        foreach (array_values($definitions) as $index => $definition) {
            $rows[] = [
                (string) $index,
                $definition->package,
                $definition->version,
                $definition->isCommercial() ? 'commercial' : 'community',
                InstalledVersions::isInstalled($definition->package) ? '✓' : '—',
            ];
        }

        $io->section('Available plugins');
        $io->table(['#', 'Package', 'Version', 'Type', 'Status'], $rows);

        $answer = $io->ask('Select plugin indices (comma separated for multiple)');
        if ($answer === null || trim($answer) === '') {
            return [];
        }

        $indices = array_map('trim', explode(',', (string) $answer));
        $indices = array_values(array_filter($indices, static fn ($i) => $i !== ''));

        $selected = [];
        foreach ($indices as $idx) {
            if (!ctype_digit($idx)) {
                $io->warning(sprintf('Ignoring invalid selection "%s" (not a number).', $idx));
                continue;
            }

            $position = (int) $idx;
            if (!isset($definitions[$position])) {
                $io->warning(sprintf('Ignoring out-of-range selection "%d".', $position));
                continue;
            }

            $selected[] = $definitions[$position];
        }

        // Deduplicate while keeping order
        $unique = [];
        $seen = [];
        foreach ($selected as $def) {
            $key = $def->package . '@' . $def->version;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $def;
            }
        }

        return $unique;
    }

    private function sortPlugins(PluginDefinition $a, PluginDefinition $b): int
    {
        $byPackage = strcmp($a->package, $b->package);
        if ($byPackage !== 0) {
            return $byPackage;
        }

        return $this->compareVersions($b->version, $a->version);
    }

    private function compareVersions(string $left, string $right): int
    {
        $pattern = '/^\d+(\.\d+)*$/';
        if (preg_match($pattern, $left) && preg_match($pattern, $right)) {
            return version_compare($left, $right);
        }

        return strcmp($left, $right);
    }

    private function runMigrationsAndReloadCache(SymfonyStyle $io, OutputInterface $output): void
    {
        $io->section('Running database migrations');
        $migrate = new \Symfony\Component\Process\Process([
            'php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--ansi',
        ], $this->projectDir);
        $migrate
            ->setTty(\Symfony\Component\Process\Process::isTtySupported())
            ->setTimeout(0)
            ->mustRun(static fn ($type, $buffer) => $output->write($buffer));

        $io->section('Clearing and warming up cache');
        $clear = new \Symfony\Component\Process\Process([
            'php', 'bin/console', 'cache:clear', '--ansi',
        ], $this->projectDir);
        $clear
            ->setTty(\Symfony\Component\Process\Process::isTtySupported())
            ->setTimeout(0)
            ->mustRun(static fn ($type, $buffer) => $output->write($buffer));

        $io->success('Migrations executed and cache reloaded.');
    }
}
