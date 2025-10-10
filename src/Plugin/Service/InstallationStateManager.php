<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Service;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

final class InstallationStateManager
{
    private FilesystemAdapter $cache;
    private string $lockFile;

    public function __construct(string $cacheDir)
    {
        $this->cache = new FilesystemAdapter('plugin_installation', 0, $cacheDir);
        $this->lockFile = $cacheDir . '/store.lock';
    }

    public function startInstallation(string $installationId, string $pluginName): void
    {
        $this->setStoreLock();
        
        $this->cache->get('installation_' . $installationId, function (ItemInterface $item) use ($pluginName, $installationId) {
            $item->expiresAfter(3600);
            
            return [
                'id' => $installationId,
                'plugin' => $pluginName,
                'status' => 'pending',
                'progress' => 0,
                'message' => 'Installation queued',
                'startedAt' => new \DateTimeImmutable(),
            ];
        });

        $this->cache->get('current_installation', function (ItemInterface $item) use ($installationId) {
            $item->expiresAfter(3600);
            return $installationId;
        });
    }

    public function updateInstallation(string $installationId, array $data): void
    {
        $installation = $this->cache->getItem('installation_' . $installationId);
        $currentData = $installation->get() ?? [];
        
        $installation->set(array_merge($currentData, $data, [
            'updatedAt' => new \DateTimeImmutable(),
        ]));
        $installation->expiresAfter(3600);
        
        $this->cache->save($installation);
    }

    public function getInstallationStatus(string $installationId): ?array
    {
        $item = $this->cache->getItem('installation_' . $installationId);
        
        return $item->isHit() ? $item->get() : null;
    }

    public function getCurrentInstallation(): ?array
    {
        $currentId = $this->cache->get('current_installation', function () {
            return null;
        });

        if (!$currentId) {
            return null;
        }

        return $this->getInstallationStatus($currentId);
    }

    public function isInstallationInProgress(): bool
    {
        return file_exists($this->lockFile);
    }

    public function setStoreLock(): void
    {
        file_put_contents($this->lockFile, json_encode([
            'locked_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'reason' => 'Plugin installation in progress',
        ]));
    }

    public function clearStoreLock(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }

        $this->cache->deleteItem('current_installation');
    }

    public function getStoreLockInfo(): ?array
    {
        if (!file_exists($this->lockFile)) {
            return null;
        }

        $content = file_get_contents($this->lockFile);
        
        return json_decode($content, true);
    }
}