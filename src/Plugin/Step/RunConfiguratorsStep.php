<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Configurator\ConfiguratorInterface;
use Sylius\StoreAssemblerBundle\Plugin\B2BKitSupport;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;

/** @experimental */
final class RunConfiguratorsStep implements InstallStepInterface
{
    private const B2B_KIT_PACKAGE = 'sylius/b2b-kit';

    public function __construct(private readonly B2BKitSupport $b2bKitSupport)
    {
    }

    public function run(StepContext $context): void
    {
        /** @var array<string, string> $supported */
        $supported = $context->get(StepContext::KEY_SUPPORTED_PLUGINS, []);
        if ($supported === []) {
            return;
        }

        /** @var array<string, array{path: string, data: array<string, mixed>}> $manifests */
        $manifests = $context->get(StepContext::KEY_PLUGIN_MANIFESTS, []);
        $io = $context->io();
        $output = $context->output();
        $projectDir = $context->projectDir();

        foreach ($supported as $package => $version) {
            if ($this->isB2BKit($package)) {
                $this->b2bKitSupport->configure($io, $output);
                continue;
            }

            if (!isset($manifests[$package])) {
                continue;
            }

            $configurators = $manifests[$package]['data']['configurators'] ?? [];
            if (!is_array($configurators)) {
                continue;
            }

            foreach ($configurators as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $class = $entry['class'] ?? null;
                if (!is_string($class) || $class === '') {
                    throw new \RuntimeException('Configurator class must be defined in manifest.');
                }

                if (!class_exists($class)) {
                    throw new \RuntimeException(sprintf('Configurator class %s not found', $class));
                }

                $configurator = new $class();
                if (!$configurator instanceof ConfiguratorInterface) {
                    throw new \RuntimeException(sprintf('%s must implement ConfiguratorInterface', $class));
                }

                $configurator->configure($io, $projectDir, $entry);
            }
        }

        $io->success('[Plugin Installer] All supported plugins have been processed.');
    }

    private function isB2BKit(string $package): bool
    {
        return strtolower($package) === self::B2B_KIT_PACKAGE;
    }
}
