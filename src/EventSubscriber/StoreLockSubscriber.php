<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Sylius\StoreAssemblerBundle\Service\InstallationStateManager;
use Twig\Environment;

final class StoreLockSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private InstallationStateManager $installationStateManager,
        private Environment $twig
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 31],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/admin/plugins') || 
            str_starts_with($path, '/_wdt') || 
            str_starts_with($path, '/_profiler')) {
            return;
        }

        if (!$this->installationStateManager->isInstallationInProgress()) {
            return;
        }

        $lockInfo = $this->installationStateManager->getStoreLockInfo();
        $currentInstallation = $this->installationStateManager->getCurrentInstallation();

        $html = $this->twig->render('@SyliusStoreAssemblerBundle/StoreLock/maintenance.html.twig', [
            'lockInfo' => $lockInfo,
            'installation' => $currentInstallation,
        ]);

        $response = new Response($html, Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Retry-After', '60');
        
        $event->setResponse($response);
    }
}