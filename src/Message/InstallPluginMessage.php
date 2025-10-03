<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Message;

final class InstallPluginMessage
{
    public function __construct(
        private string $pluginName,
        private string $installationId
    ) {
    }

    public function getPluginName(): string
    {
        return $this->pluginName;
    }

    public function getInstallationId(): string
    {
        return $this->installationId;
    }
}