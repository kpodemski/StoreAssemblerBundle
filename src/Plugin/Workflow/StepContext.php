<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Workflow;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @experimental */
final class StepContext
{
    public const KEY_PLUGIN_DEFINITIONS = 'plugin_definitions';
    public const KEY_PLUGIN_MANIFESTS = 'plugin_manifests';
    public const KEY_SUPPORTED_PLUGINS = 'supported_plugins';
    public const KEY_UNSUPPORTED_PLUGINS = 'unsupported_plugins';

    /**
     * @var array<string, mixed>
     */
    private array $storage = [];

    /**
     * @param array<string, string> $plugins
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly OutputInterface $output,
        private readonly array $plugins,
        private readonly string $projectDir,
    ) {
    }

    public function io(): SymfonyStyle
    {
        return $this->io;
    }

    public function output(): OutputInterface
    {
        return $this->output;
    }

    /**
     * @return array<string, string>
     */
    public function plugins(): array
    {
        return $this->plugins;
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    public function set(string $key, mixed $value): void
    {
        $this->storage[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->storage);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->storage[$key] ?? $default;
    }
}
