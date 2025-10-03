<?php

namespace Sylius\StoreAssemblerBundle\Util;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;

/** @experimental */
final class ManifestLocator
{
    /**
     * Locate the best manifest.json for an installed plugin version.
     *
     * @param string $projectDir Absolute path to project root
     * @param string $package    Package name, e.g. 'sylius/cms-plugin'
     *
     * @return string Absolute path to manifest.json
     * @throws \RuntimeException if no suitable manifest found or plugin unsupported
     */
    public static function locate(string $projectDir, string $package, ?string $preferredVersion = null): string
    {
        $parts = explode('/', $package, 2);
        $vendor = $parts[0] ?? 'sylius';
        $name = $parts[1] ?? $parts[0];

        // Candidate bases: prefer vendor-provided manifests, then project-level overrides
        $vendorBase = rtrim($projectDir, '/\\') . "/vendor/sylius/store-assembler/config/plugins/{$vendor}/{$name}/";
        $projectBase = rtrim($projectDir, '/\\') . "/config/plugins/{$vendor}/{$name}/";
        $bases = [];
        if (is_dir($vendorBase)) {
            $bases[] = $vendorBase;
        }
        if (is_dir($projectBase)) {
            $bases[] = $projectBase;
        }
        if ($bases === []) {
            throw new \RuntimeException(
                sprintf(
                    'Plugin "%s" is configured but not supported: missing directory in either "%s" or "%s".',
                    $package,
                    $vendorBase,
                    $projectBase,
                )
            );
        }

        // Determine target version (major.minor). Prefer preferredVersion, else try installed version, else fallback to highest available
        $target = null;
        if ($preferredVersion !== null && $preferredVersion !== '') {
            $parser = new VersionParser();
            try {
                $normalized = $parser->normalize($preferredVersion);
                $v = explode('.', $normalized);
                $target = $v[0] . '.' . $v[1];
            } catch (\Throwable) {
                if (preg_match('/^(\d+)\.(\d+)/', (string) $preferredVersion, $m) === 1) {
                    $target = $m[1] . '.' . $m[2];
                }
            }
        } else {
            if (InstalledVersions::isInstalled("{$vendor}/{$name}")) {
                $installed = InstalledVersions::getVersion("{$vendor}/{$name}");
                if ($installed !== null) {
                    $parser = new VersionParser();
                    try {
                        $normalized = $parser->normalize($installed);
                        $v = explode('.', $normalized);
                        $target = $v[0] . '.' . $v[1];
                    } catch (\Throwable) {
                        if (preg_match('/^(\d+)\.(\d+)/', (string) $installed, $m) === 1) {
                            $target = $m[1] . '.' . $m[2];
                        }
                    }
                }
            }
        }

        // Iterate bases in order and find the best matching manifest
        foreach ($bases as $baseDir) {
            // Collect available version directories
            $dirs = array_filter(scandir($baseDir) ?: [], function ($d) use ($baseDir) {
                return is_dir($baseDir . $d) && preg_match('/^\d+\.\d+$/', $d);
            });

            // Sort descending: highest versions first
            usort($dirs, fn ($a, $b) => version_compare($b, $a));

            if ($target !== null) {
                // Find first dir <= target
                foreach ($dirs as $ver) {
                    if (version_compare($ver, $target, '<=')) {
                        $path = $baseDir . $ver . '/manifest.json';
                        if (is_file($path)) {
                            return $path;
                        }
                    }
                }
            } else {
                // No version context; fall back to highest available
                foreach ($dirs as $ver) {
                    $path = $baseDir . $ver . '/manifest.json';
                    if (is_file($path)) {
                        return $path;
                    }
                }
            }
        }

        throw new \RuntimeException(
            sprintf(
                'No manifest found <= version %s for plugin "%s" in %s',
                $target,
                $package,
                $vendorBase . ' or ' . $projectBase
            )
        );
    }
}
