<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Service;

use Sylius\StoreAssemblerBundle\Plugin\PluginDefinition;

/** @experimental */
final class ComposerMetadataResolver
{
    /**
     * @param array<string, PluginDefinition> $definitions
     */
    public function determineRequiredStability(array $definitions): ?string
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
            $stability = $definition->manifest['minimum-stability'] ?? null;
            if (!is_string($stability) || $stability === '') {
                continue;
            }

            $normalized = strtolower($stability);
            if (!array_key_exists($normalized, $order)) {
                continue;
            }

            $rank = $order[$normalized];
            if ($rank < $requiredRank) {
                $requiredRank = $rank;
                $required = $normalized === 'rc' ? 'RC' : $normalized;
            }
        }

        return $required;
    }

    /**
     * @param array<string, PluginDefinition> $definitions
     *
     * @return array<string, string>
     */
    public function collectStabilityFlags(array $definitions): array
    {
        $flags = [];

        foreach ($definitions as $definition) {
            $stability = $definition->manifest['minimum-stability'] ?? null;
            if (!is_string($stability) || $stability === '') {
                continue;
            }

            $normalized = strtolower($stability);
            if (!in_array($normalized, ['dev', 'alpha', 'beta', 'rc', 'stable'], true)) {
                continue;
            }

            $flags[$definition->package] = $normalized === 'rc' ? 'RC' : $normalized;
        }

        return $flags;
    }
}
