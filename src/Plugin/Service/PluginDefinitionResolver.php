<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Service;

use Sylius\StoreAssemblerBundle\Plugin\PluginCatalog;
use Sylius\StoreAssemblerBundle\Plugin\PluginDefinition;

/** @experimental */
final class PluginDefinitionResolver
{
    public function __construct(
        private readonly PluginCatalog $catalog,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string, string> $plugins
     *
     * @return array<string, PluginDefinition>
     */
    public function resolve(array $plugins): array
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
}
