<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Plugin\PluginDefinition;
use Sylius\StoreAssemblerBundle\Plugin\Service\PluginDefinitionResolver;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;
use Sylius\StoreAssemblerBundle\Util\ManifestLocator;
use Symfony\Component\Process\Process;

/** @experimental */
final class ProcessRectorConfigStep extends AbstractPluginDefinitionsStep implements PrepareStepInterface
{
    public function __construct(
        private readonly string $projectDir,
        PluginDefinitionResolver $definitionResolver,
    ) {
        parent::__construct($definitionResolver);
    }

    public function run(StepContext $context): void
    {
        $definitions = $this->definitions($context);
        if ($definitions === []) {
            return;
        }

        $io = $context->io();
        $output = $context->output();
        $projectDir = $this->projectDir;

        $rectorSets = [];
        $rectorConfigs = [];

        foreach ($definitions as $definition) {
            try {
                $manifestPath = ManifestLocator::locate($projectDir, $definition->package, $definition->version);
                $data = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            foreach (($data['rector-sets'] ?? []) as $reference) {
                if (is_string($reference)) {
                    $rectorSets[] = $reference;
                }
            }

            $configEntries = $data['rector-config'] ?? $data['rector-configs'] ?? [];
            if (is_string($configEntries)) {
                $configEntries = [$configEntries];
            }

            if (is_array($configEntries)) {
                $baseDir = dirname($manifestPath);
                foreach ($configEntries as $entry) {
                    if (!is_string($entry) || $entry === '') {
                        continue;
                    }

                    $resolved = $entry;
                    if (!preg_match('#^(?:/|[A-Za-z]:[\\/])#', $entry)) {
                        $resolved = rtrim($baseDir, '/\\') . '/' . ltrim($entry, '/\\');
                    }

                    if (is_file($resolved)) {
                        $rectorConfigs[] = $resolved;
                    }
                }
            }
        }

        if ($rectorSets === [] && $rectorConfigs === []) {
            $io->note('[Plugin Preparer] No plugin-defined Rector sets/configs found; skipping Rector.');

            return;
        }

        $undefinedSets = [];
        foreach ($rectorSets as $reference) {
            if (!is_string($reference) || !str_contains($reference, '::')) {
                continue;
            }

            if (!defined($reference)) {
                $undefinedSets[] = $reference;
            }
        }

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

        $targetDir = rtrim($projectDir, '/\\') . '/var/store-assembler';
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

    foreach ({$setsExport} as \$setRef) {
        if (is_string(\$setRef) && str_contains(\$setRef, '::') && defined(\$setRef)) {
            \$rectorConfig->sets([constant(\$setRef)]);
        }
    }

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

        $exitCode = (new Process([
            'vendor/bin/rector',
            'process',
            'src',
            '--config=' . $generatedConfig,
        ], $projectDir))
            ->setTimeout(0)
            ->mustRun(static fn ($type, $buffer) => $output->write($buffer))
            ->getExitCode();

        if ($exitCode !== 0) {
            $message = sprintf('Rector exited with code %d', (int) $exitCode);
            throw new \RuntimeException($message);
        }

        $io->success('Rector completed successfully.');
    }
}
