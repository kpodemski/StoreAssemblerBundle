<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin;

use Symfony\Component\Finder\Finder;

/** @experimental */
final class PluginCatalog
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return list<PluginDefinition>
     */
    public function all(): array
    {
        $definitions = [];

        foreach ($this->catalogRoots() as $root) {
            $vendorFinder = (new Finder())
                ->depth('== 0')
                ->directories()
                ->in($root);

            foreach ($vendorFinder as $vendorDir) {
                $vendor = $vendorDir->getBasename();
                $pluginFinder = (new Finder())
                    ->depth('== 0')
                    ->directories()
                    ->in($vendorDir->getRealPath() ?: $vendorDir->getPathname());

                foreach ($pluginFinder as $pluginDir) {
                    $name = $pluginDir->getBasename();
                    $versionFinder = (new Finder())
                        ->depth('== 0')
                        ->directories()
                        ->in($pluginDir->getRealPath() ?: $pluginDir->getPathname());

                    foreach ($versionFinder as $versionDir) {
                        $version = $versionDir->getBasename();
                        $manifestPath = ($versionDir->getRealPath() ?: $versionDir->getPathname()) . '/manifest.json';
                        if (!is_file($manifestPath)) {
                            continue;
                        }

                        try {
                            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            continue;
                        }

                        $package = sprintf('%s/%s', $vendor, $name);
                        $definitions[sprintf('%s@%s', $package, $version)] = new PluginDefinition(
                            $vendor,
                            $name,
                            $package,
                            $version,
                            $manifestPath,
                            $manifest,
                        );
                    }
                }
            }
        }

        ksort($definitions);

        return array_values($definitions);
    }

    public function latest(string $package): ?PluginDefinition
    {
        $matches = array_filter(
            $this->all(),
            static fn (PluginDefinition $definition) => strtolower($definition->package) === strtolower($package),
        );

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            fn (PluginDefinition $a, PluginDefinition $b): int => $this->compareVersions($b->version, $a->version)
        );

        return $matches[0];
    }

    public function find(string $package, ?string $version = null): ?PluginDefinition
    {
        $normalizedPackage = strtolower($package);
        $normalizedVersion = $this->normalizeVersion($version);

        $matches = array_filter(
            $this->all(),
            static fn (PluginDefinition $definition) => strtolower($definition->package) === $normalizedPackage,
        );

        if ($matches === []) {
            return null;
        }

        if ($normalizedVersion === null) {
            return $this->latest($package);
        }

        foreach ($matches as $definition) {
            if ($definition->version === $normalizedVersion) {
                return $definition;
            }
        }

        return null;
    }

    public function manifestPath(string $package, string $version): ?string
    {
        [$vendor, $name] = $this->splitPackage($package);
        $normalizedVersion = $this->normalizeVersion($version);

        foreach ($this->catalogRoots() as $root) {
            $manifestPath = sprintf(
                '%s/%s/%s/%s/manifest.json',
                rtrim($root, '/\\'),
                $vendor,
                $name,
                $normalizedVersion
            );

            if (is_file($manifestPath)) {
                return $manifestPath;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function catalogRoots(): array
    {
        $roots = [];

        $vendorRoot = rtrim($this->projectDir, '/\\') . '/vendor/sylius/store-assembler/config/plugins';
        if (is_dir($vendorRoot)) {
            $roots[] = $vendorRoot;
        }

        $localRoot = rtrim($this->projectDir, '/\\') . '/config/plugins';
        if (is_dir($localRoot) && !in_array($localRoot, $roots, true)) {
            $roots[] = $localRoot;
        }

        return $roots;
    }

    private function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        if (preg_match('/\d+\.\d+/', $version, $matches) === 1) {
            return $matches[0];
        }

        return $version;
    }

    private function splitPackage(string $package): array
    {
        $parts = explode('/', $package, 2);
        $vendor = $parts[0];
        $name = $parts[1] ?? $parts[0];

        return [$vendor, $name];
    }

    private function compareVersions(string $left, string $right): int
    {
        $numeric = '/^\d+(\.\d+)*$/';
        if (preg_match($numeric, $left) && preg_match($numeric, $right)) {
            return version_compare($left, $right);
        }

        return strcmp($left, $right);
    }

    /**
     * @return array<string, array{name: string, vendor?: string, description?: string, version?: string, repository?: string, stars?: int}>
     */
    public function getAvailablePlugins(): array
    {
        $plugins = [];
        $definitions = $this->all();

        $latestByPackage = [];
        foreach ($definitions as $definition) {
            if (!isset($latestByPackage[$definition->package]) || 
                $this->compareVersions($definition->version, $latestByPackage[$definition->package]->version) > 0) {
                $latestByPackage[$definition->package] = $definition;
            }
        }

        foreach ($latestByPackage as $definition) {
            $manifest = $definition->manifest;
            $plugins[$definition->package] = [
                'name' => $definition->package,
                'vendor' => $definition->vendor,
                'description' => $manifest['description'] ?? null,
                'version' => $definition->version,
                'repository' => $manifest['repository'] ?? null,
                'stars' => $manifest['stars'] ?? 0,
            ];
        }

        return $plugins;
    }

    /**
     * @return array{name: string, vendor?: string, description?: string, version?: string, repository?: string, stars?: int}|null
     */
    public function getPlugin(string $package): ?array
    {
        $plugins = $this->getAvailablePlugins();
        return $plugins[$package] ?? null;
    }
}
