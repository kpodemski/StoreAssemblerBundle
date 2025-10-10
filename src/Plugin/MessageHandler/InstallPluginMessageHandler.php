<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\MessageHandler;

use Psr\Log\LoggerInterface;
use Sylius\StoreAssemblerBundle\Plugin\Message\InstallPluginMessage;
use Sylius\StoreAssemblerBundle\Plugin\Service\InstallationStateManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final class InstallPluginMessageHandler
{
    public function __construct(
        private InstallationStateManager $installationStateManager,
        private LoggerInterface $logger,
        private string $projectDir
    ) {
    }

    public function __invoke(InstallPluginMessage $message): void
    {
        $installationId = $message->getInstallationId();
        $pluginName = $message->getPluginName();

        try {
            $this->logger->info('Starting plugin installation', [
                'plugin' => $pluginName,
                'installationId' => $installationId,
            ]);

            $this->installationStateManager->updateInstallation($installationId, [
                'status' => 'running',
                'progress' => 10,
                'message' => 'Preparing installation...',
            ]);

            $process = new Process(
                ['php', 'bin/console', 'sylius:plugin:install', $pluginName, '--no-interaction'],
                $this->projectDir,
                null,
                null,
                600
            );

            $output = '';
            $process->run(function ($type, $buffer) use ($installationId, &$output) {
                $output .= $buffer;
                $this->logger->debug('Installation output', ['buffer' => $buffer]);

                if (str_contains($buffer, 'Downloading')) {
                    $this->installationStateManager->updateInstallation($installationId, [
                        'progress' => 30,
                        'message' => 'Downloading plugin...',
                    ]);
                } elseif (str_contains($buffer, 'Installing')) {
                    $this->installationStateManager->updateInstallation($installationId, [
                        'progress' => 50,
                        'message' => 'Installing dependencies...',
                    ]);
                } elseif (str_contains($buffer, 'Configuring')) {
                    $this->installationStateManager->updateInstallation($installationId, [
                        'progress' => 70,
                        'message' => 'Configuring plugin...',
                    ]);
                } elseif (str_contains($buffer, 'Clearing cache')) {
                    $this->installationStateManager->updateInstallation($installationId, [
                        'progress' => 90,
                        'message' => 'Clearing cache...',
                    ]);
                }
            });

            if (!$process->isSuccessful()) {
                throw new \RuntimeException(sprintf(
                    'Plugin installation failed: %s',
                    $process->getErrorOutput() ?: $output
                ));
            }

            $this->installationStateManager->updateInstallation($installationId, [
                'status' => 'completed',
                'progress' => 100,
                'message' => 'Installation completed successfully!',
                'output' => $output,
            ]);

            $this->logger->info('Plugin installed successfully', [
                'plugin' => $pluginName,
                'installationId' => $installationId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Plugin installation failed', [
                'plugin' => $pluginName,
                'installationId' => $installationId,
                'error' => $e->getMessage(),
            ]);

            $this->installationStateManager->updateInstallation($installationId, [
                'status' => 'failed',
                'progress' => 0,
                'message' => 'Installation failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->installationStateManager->clearStoreLock();
        }
    }
}