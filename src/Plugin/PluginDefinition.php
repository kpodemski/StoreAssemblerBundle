<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin;

/** @experimental */
final class PluginDefinition
{
    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public readonly string $vendor,
        public readonly string $name,
        public readonly string $package,
        public readonly string $version,
        public readonly string $manifestPath,
        public readonly array $manifest,
    ) {
    }

    public function type(): string
    {
        return strtolower((string) ($this->manifest['type'] ?? 'community'));
    }

    public function isCommercial(): bool
    {
        return $this->type() === 'commercial';
    }

    public function label(): string
    {
        return sprintf('%s@%s', $this->package, $this->version);
    }
}
