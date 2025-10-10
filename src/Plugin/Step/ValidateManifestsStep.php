<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;
use Sylius\StoreAssemblerBundle\Util\ManifestLocator;

/** @experimental */
final class ValidateManifestsStep implements InstallStepInterface
{
    private const B2B_KIT_PACKAGE = 'sylius/b2b-kit';

    public function __construct(private readonly string $projectDir)
    {
    }

    public function run(StepContext $context): void
    {
        $supported = [];
        $unsupported = [];
        $manifests = [];

        foreach ($context->plugins() as $package => $version) {
            if ($this->isB2BKit($package)) {
                $supported[$package] = $version;
                continue;
            }

            try {
                $manifestPath = ManifestLocator::locate($this->projectDir, $package, $version);
                $manifestData = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            } catch (\RuntimeException $exception) {
                $unsupported[] = sprintf('%s@%s', $package, $version);
                continue;
            } catch (\JsonException $exception) {
                $message = sprintf('Failed to decode manifest for %s@%s: %s', $package, $version, $exception->getMessage());
                throw new \RuntimeException($message, 0, $exception);
            }

            $supported[$package] = $version;
            $manifests[$package] = [
                'path' => $manifestPath,
                'data' => $manifestData,
            ];
        }

        if ($unsupported !== []) {
            $context->io()->warning(
                sprintf(
                    "The following plugins are configured but not supported (missing manifest): %s.\n" .
                    'To support them, add a manifest under config/plugins/{vendor}/{name}/{version} or remove them from store-preset.',
                    implode(', ', $unsupported)
                )
            );
        }

        if ($supported === []) {
            $context->io()->warning('No supported plugins to install after manifest validation.');
        } else {
            $context->io()->title('[Plugin Installer] Installing plugins');
        }

        $context->set(StepContext::KEY_SUPPORTED_PLUGINS, $supported);
        $context->set(StepContext::KEY_UNSUPPORTED_PLUGINS, $unsupported);
        $context->set(StepContext::KEY_PLUGIN_MANIFESTS, $manifests);
    }

    private function isB2BKit(string $package): bool
    {
        return strtolower($package) === self::B2B_KIT_PACKAGE;
    }
}
