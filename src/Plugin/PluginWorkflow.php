<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin;

use Sylius\StoreAssemblerBundle\Configurator\ConfiguratorInterface;
use Sylius\StoreAssemblerBundle\Util\ManifestLocator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/** @experimental */
final class PluginWorkflow
{
    private const B2B_KIT_PACKAGE = 'sylius/b2b-kit';

    public function __construct(
        private readonly string $projectDir,
        private readonly PluginCatalog $catalog,
        private readonly B2BKitSupport $b2bKitSupport,
    ) {
    }

    /**
     * @param array<string, string> $plugins
     */
    public function prepare(SymfonyStyle $io, OutputInterface $output, array $plugins): void
    {
        if ($plugins === []) {
            $io->warning('No plugins provided. Nothing to prepare.');
            return;
        }

        $definitions = $this->gatherDefinitions($plugins);

        // If any selected plugin requires lower stability, relax Composer accordingly
        $requiredStability = $this->determineRequiredStability($definitions);
        if ($requiredStability !== null) {
            $io->section('[Plugin Preparer] Adjusting Composer minimum-stability');
            $io->text(sprintf(' • minimum-stability => %s (prefer-stable=true)', $requiredStability));
            (new Process(['composer', 'config', 'minimum-stability', $requiredStability], $this->projectDir))->mustRun();
            (new Process(['composer', 'config', 'prefer-stable', 'true'], $this->projectDir))->mustRun();
        }

        (new Process(
            ['composer', 'config', 'extra.symfony.allow-contrib', 'true'],
            $this->projectDir
        ))->mustRun();

        $openSource = [];
        $paid = [];
        foreach ($definitions as $definition) {
            $package = $definition->package;
            if ($definition->isPaid()) {
                $paid[$package] = $plugins[$package];
            } else {
                $openSource[$package] = $plugins[$package];
            }
        }

        // Build per-package stability flags from manifests
        $stabilityFlags = $this->collectStabilityFlags($definitions);

        if ($openSource !== []) {
            $io->section('[Plugin Preparer] Installing open-source plugins');
            foreach ($openSource as $package => $version) {
                $io->text(sprintf(' → %s:%s', $package, $version));
                $this->requireComposerPackage($io, $output, $package, $version, $stabilityFlags[$package] ?? null);
            }
        }

        if ($paid !== []) {
            $io->section('[Plugin Preparer] Configuring private Sylius repository');
            (new Process(
                ['composer', 'config', 'repositories.sylius', 'composer', 'https://sylius.repo.packagist.com/sylius/'],
                $this->projectDir
            ))->mustRun();

            $io->section('[Plugin Preparer] Checking existing credentials');
            $usernameCheck = new Process(
                ['composer', 'config', '--auth', 'http-basic.sylius.repo.packagist.com.username'],
                $this->projectDir
            );
            $passwordCheck = new Process(
                ['composer', 'config', '--auth', 'http-basic.sylius.repo.packagist.com.password'],
                $this->projectDir
            );
            $usernameCheck->run();
            $passwordCheck->run();

            $hasUsername = $usernameCheck->getExitCode() === 0 && trim($usernameCheck->getOutput()) !== '';
            $hasPassword = $passwordCheck->getExitCode() === 0 && trim($passwordCheck->getOutput()) !== '';

            if ($hasUsername && $hasPassword) {
                $io->text('✔ Found existing credentials via Composer; skipping prompt.');
            } else {
                $username = $io->ask('Sylius repo username');
                $token = $io->askHidden('Sylius repo token');

                (new Process(
                    ['composer', 'config', '--auth', 'http-basic.sylius.repo.packagist.com', $username, $token],
                    $this->projectDir
                ))->mustRun();
            }

            $io->section('[Plugin Preparer] Verifying access (dry‑run)');
            foreach ($paid as $package => $version) {
                $io->text(sprintf(' 🔍 Testing %s:%s', $package, $version));
                try {
                    (new Process(
                        ['composer', 'require', "{$package}:^{$version}", '--no-scripts', '--no-interaction', '--dry-run'],
                        $this->projectDir
                    ))
                        ->setTimeout(0)
                        ->mustRun();
                } catch (ProcessFailedException $exception) {
                    $message = sprintf(
                        'Access denied for %s:%s. Please verify your token and repository credentials.',
                        $package,
                        $version
                    );
                    throw new \RuntimeException($message, 0, $exception);
                }
            }

            $io->section('[Plugin Preparer] Installing paid plugins');
            foreach ($paid as $package => $version) {
                $io->text(sprintf(' → %s:%s', $package, $version));
                $this->requireComposerPackage($io, $output, $package, $version, $stabilityFlags[$package] ?? null);
            }
        }

        // Discover Rector sets/configs required by selected plugins from their manifests
        $rectorSets = [];
        $rectorConfigs = [];
        foreach (array_keys($plugins) as $packageName) {
            try {
                $manifestPath = ManifestLocator::locate($this->projectDir, $packageName, $plugins[$packageName] ?? null);
                $data = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                foreach (($data['rector-sets'] ?? []) as $ref) {
                    if (is_string($ref)) {
                        $rectorSets[] = $ref;
                    }
                }
                $configEntries = $data['rector-config'] ?? $data['rector-configs'] ?? [];
                if (is_string($configEntries)) {
                    $configEntries = [$configEntries];
                }
                if (is_array($configEntries)) {
                    $baseDir = dirname($manifestPath);
                    foreach ($configEntries as $cfg) {
                        if (!is_string($cfg) || $cfg === '') {
                            continue;
                        }
                        // Resolve relative to manifest dir when not absolute
                        $resolved = $cfg;
                        if (!preg_match('#^(?:/|[A-Za-z]:[\\/])#', $cfg)) {
                            $resolved = rtrim($baseDir, '/\\') . '/' . ltrim($cfg, '/\\');
                        }
                        if (is_file($resolved)) {
                            $rectorConfigs[] = $resolved;
                        }
                    }
                }
            } catch (\Throwable) {
                // ignore missing/invalid manifest here — Rector is optional per plugin
            }
        }

        // Detect undefined set constants for better diagnostics
        $undefinedSets = [];
        foreach ($rectorSets as $ref) {
            if (!is_string($ref) || !str_contains($ref, '::')) {
                continue;
            }
            // defined() should autoload classes; if it doesn't, fall back to class_exists check
            if (!defined($ref)) {
                $undefinedSets[] = $ref;
            }
        }

        if ($rectorSets === [] && $rectorConfigs === []) {
            $io->note('[Plugin Preparer] No plugin-defined Rector sets/configs found; skipping Rector.');
        } else {
            $io->title('[Plugin Preparer] Running Rector');
            if ($rectorSets !== []) {
                $io->text(' • Sets:');
                $io->listing(array_values(array_unique($rectorSets)));
            }
            if ($rectorConfigs !== []) {
                $io->text(' • Config imports:');
                $io->listing(array_values(array_unique($rectorConfigs)));
            }
            if ($undefinedSets !== []) {
                $io->note('Some Rector set constants are not defined. Ensure the relevant package is installed (e.g. sylius/sylius-rector).');
                $io->text(' • Undefined sets:');
                $io->listing(array_values(array_unique($undefinedSets)));
            }

            // Generate a minimal Rector config with the required sets
            $targetDir = rtrim($this->projectDir, '/\\') . '/var/store-assembler';
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0777, true);
            }
            $generatedConfig = $targetDir . '/rector.generated.php';

            $setsExport = var_export(array_values(array_unique($rectorSets)), true);
            $importsExport = var_export(array_values(array_unique($rectorConfigs)), true);
            $configContent = <<<PHP
<?php
declare(strict_types=1);

use Rector\\Config\\RectorConfig;

return static function (RectorConfig \$rectorConfig): void {
    \$projectDir = getcwd();
    \$rectorConfig->paths([\$projectDir . '/src']);

    // Enable plugin-provided sets
    foreach ({$setsExport} as \$setRef) {
        if (is_string(\$setRef) && str_contains(\$setRef, '::') && defined(\$setRef)) {
            \$rectorConfig->sets([constant(\$setRef)]);
        }
    }

    // Import plugin-provided Rector configs
    foreach ({$importsExport} as \$importPath) {
        if (is_string(\$importPath) && file_exists(\$importPath)) {
            \$rectorConfig->import(\$importPath);
        }
    }

    \$rectorConfig->importNames();
    \$rectorConfig->removeUnusedImports();
};
PHP;

            file_put_contents($generatedConfig, $configContent);

            $exitCode = $this->runCommand([
                'vendor/bin/rector',
                'process',
                'src',
                '--config=' . $generatedConfig,
            ], $output, $io);

            if ($exitCode !== 0) {
                $message = sprintf('Rector exited with code %d', $exitCode);
                throw new \RuntimeException($message);
            }

            $io->success('Rector completed successfully.');
        }
        $io->success('[Plugin Preparer] All plugins installed successfully.');
    }

    private function requireComposerPackage(SymfonyStyle $io, OutputInterface $output, string $package, string $version, ?string $stability = null): void
    {
        $constraint = "^{$version}";
        if ($stability !== null) {
            $constraint .= '@' . $stability;
        }

        (new Process(
            ['composer', 'require', "{$package}:{$constraint}", '--no-scripts', '--no-interaction'],
            $this->projectDir
        ))
            ->setTimeout(0)
            ->mustRun(static fn ($type, $buffer) => $output->write($buffer));
    }

    private function isB2BKit(string $package): bool
    {
        return strtolower($package) === self::B2B_KIT_PACKAGE;
    }

    /**
     * @param array<string, string> $plugins
     */
    public function install(SymfonyStyle $io, OutputInterface $output, array $plugins): void
    {
        if ($plugins === []) {
            $io->warning('No plugins provided. Nothing to install.');
            return;
        }

        $supported = [];
        $unsupported = [];
        $manifests = [];

        foreach ($plugins as $package => $version) {
            if ($this->isB2BKit($package)) {
                $supported[$package] = $version;
                continue;
            }

            try {
                $manifests[$package] = ManifestLocator::locate($this->projectDir, $package, $version);
                $supported[$package] = $version;
            } catch (\RuntimeException) {
                $unsupported[] = sprintf('%s@%s', $package, $version);
            }
        }

        if ($unsupported !== []) {
            $io->warning(
                sprintf(
                    "The following plugins are configured but not supported (missing manifest): %s.\n" .
                    "To support them, add a manifest under config/plugins/{vendor}/{name}/{version} or remove them from store-preset.",
                    implode(', ', $unsupported)
                )
            );
        }

        if ($supported === []) {
            $io->warning('No supported plugins to install after manifest validation.');
            return;
        }

        $io->title('[Plugin Installer] Installing plugins');

        foreach (array_keys($supported) as $packageName) {
            if ($this->isB2BKit($packageName)) {
                $this->b2bKitSupport->configure($io, $output);
                continue;
            }

            $manifestPath = $manifests[$packageName] ?? ManifestLocator::locate($this->projectDir, $packageName, $supported[$packageName] ?? null);
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

            foreach ($manifest['steps'] ?? [] as $cmd) {
                $io->section('[PluginPrepare] Running shell step');
                Process::fromShellCommandline($cmd, $this->projectDir)
                    ->run(static fn ($type, $buffer) => $output->write($buffer));
            }

            foreach ($manifest['configurators'] ?? [] as $entry) {
                $class = $entry['class'] ?? null;
                if ($class === null || !class_exists($class)) {
                    throw new \RuntimeException("Configurator class {$class} not found");
                }

                $configurator = new $class();
                if (!$configurator instanceof ConfiguratorInterface) {
                    throw new \RuntimeException("{$class} must implement ConfiguratorInterface");
                }

                $configurator->configure($io, $this->projectDir, $entry);
            }
        }

        $io->success('[Plugin Installer] All supported plugins have been processed.');
    }

    /**
     * @param array<string, string> $plugins
     *
     * @return array<string, PluginDefinition>
     */
    private function gatherDefinitions(array $plugins): array
    {
        $definitions = [];

        foreach ($plugins as $package => $version) {
            $definition = $this->catalog->find($package, $version);
            if ($definition === null) {
                $message = sprintf(
                    'Plugin "%s"@"%s" is configured but missing manifest.json in "%s".',
                    $package,
                    $version,
                    $this->expectedManifestDirectory($package, $version)
                );
                throw new \RuntimeException($message);
            }

            $definitions[$definition->package] = $definition;
        }

        return $definitions;
    }

    private function expectedManifestDirectory(string $package, string $version): string
    {
        [$vendor, $name] = $this->splitPackage($package);
        $normalizedVersion = $this->normalizeVersion($version);

        $vendorRoot = rtrim($this->projectDir, '/\\') . '/vendor/sylius/store-assembler/config/plugins';
        $base = is_dir($vendorRoot) ? $vendorRoot : rtrim($this->projectDir, '/\\') . '/config/plugins';

        return sprintf('%s/%s/%s/%s', rtrim($base, '/\\'), $vendor, $name, $normalizedVersion);
    }

    private function splitPackage(string $package): array
    {
        $parts = explode('/', $package, 2);
        $vendor = $parts[0];
        $name = $parts[1] ?? $parts[0];

        return [$vendor, $name];
    }

    private function normalizeVersion(string $version): string
    {
        $normalized = preg_replace('/^[^0-9]*/', '', $version);

        return $normalized !== '' ? $normalized : $version;
    }

    private function runCommand(array $command, OutputInterface $output, SymfonyStyle $io): int
    {
        $process = new Process($command, $this->projectDir);
        $process
            ->setTty(Process::isTtySupported())
            ->setTimeout(0)
            ->mustRun(static fn ($type, $buffer) => $output->write($buffer));

        return $process->getExitCode();
    }

    /**
     * @param array<string, PluginDefinition> $definitions
     */
    private function determineRequiredStability(array $definitions): ?string
    {
        $order = [
            'dev' => 0,
            'alpha' => 1,
            'beta' => 2,
            'rc' => 3,
            'stable' => 4,
        ];

        $required = null;
        $requiredRank = 999;

        foreach ($definitions as $definition) {
            $s = $definition->manifest['minimum-stability'] ?? null;
            if (!is_string($s) || $s === '') {
                continue;
            }
            $norm = strtolower($s);
            if (!array_key_exists($norm, $order)) {
                continue;
            }
            $rank = $order[$norm];
            if ($rank < $requiredRank) {
                $requiredRank = $rank;
                $required = $norm === 'rc' ? 'RC' : $norm; // Composer uses RC uppercase
            }
        }

        return $required;
    }

    /**
     * @param array<string, PluginDefinition> $definitions
     * @return array<string, string> map package => stability flag (e.g. dev, alpha)
     */
    private function collectStabilityFlags(array $definitions): array
    {
        $flags = [];
        foreach ($definitions as $definition) {
            $s = $definition->manifest['minimum-stability'] ?? null;
            if (!is_string($s) || $s === '') {
                continue;
            }
            $norm = strtolower($s);
            if (in_array($norm, ['dev', 'alpha', 'beta', 'rc', 'stable'], true)) {
                $flags[$definition->package] = $norm === 'rc' ? 'RC' : $norm;
            }
        }

        return $flags;
    }
}
