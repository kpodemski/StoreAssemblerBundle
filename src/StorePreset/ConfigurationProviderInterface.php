<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\StorePreset;

/** @experimental */
interface ConfigurationProviderInterface
{
    /**
     * @return array<string, string>
     */
    public function getPlugins(): array;

    /**
     * @return array<string, mixed>
     */
    public function getThemes(): array;

    public function getFixturesFilePath(): string;

    public function getFixturesSuiteName(): string;
}
