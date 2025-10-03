<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Controller\Admin;

use Sylius\StoreAssemblerBundle\Message\InstallPluginMessage;
use Sylius\StoreAssemblerBundle\Plugin\PluginCatalog;
use Sylius\StoreAssemblerBundle\Service\InstallationStateManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class PluginController extends AbstractController
{
    public function __construct(
        private readonly PluginCatalog $pluginCatalog,
        private readonly MessageBusInterface $messageBus,
        private readonly InstallationStateManager $installationStateManager
    ) {
    }

    public function index(): Response
    {
        $plugins = $this->pluginCatalog->getAvailablePlugins();
        $installationStatus = $this->installationStateManager->getCurrentInstallation();

        return $this->render('@SyliusStoreAssemblerBundle/Admin/Plugin/index.html.twig', [
            'plugins' => $plugins,
            'installationStatus' => $installationStatus,
        ]);
    }

    public function install(Request $request): JsonResponse
    {
        $pluginName = $request->request->get('plugin');
        
        if (!$pluginName) {
            return new JsonResponse(['error' => 'Plugin name is required'], 400);
        }

        if ($this->installationStateManager->isInstallationInProgress()) {
            return new JsonResponse([
                'error' => 'Another installation is already in progress'
            ], 409);
        }

        $plugin = $this->pluginCatalog->getPlugin($pluginName);
        
        if (!$plugin) {
            return new JsonResponse(['error' => 'Plugin not found'], 404);
        }

        $installationId = uniqid('install_', true);
        $this->installationStateManager->startInstallation($installationId, $pluginName);

        $this->messageBus->dispatch(new InstallPluginMessage($pluginName, $installationId));

        return new JsonResponse([
            'success' => true,
            'installationId' => $installationId,
            'message' => sprintf('Installation of %s has been queued', $pluginName)
        ]);
    }

    public function status(string $installationId): JsonResponse
    {
        $status = $this->installationStateManager->getInstallationStatus($installationId);
        
        if (!$status) {
            return new JsonResponse(['error' => 'Installation not found'], 404);
        }

        return new JsonResponse($status);
    }
}