<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\DependencyInjection\Compiler;

use Sylius\StoreAssemblerBundle\Plugin\Step\InstallStepInterface;
use Sylius\StoreAssemblerBundle\Plugin\Step\PrepareStepInterface;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** @experimental */
final class WorkflowDefinitionPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        if (!is_string($projectDir)) {
            throw new \RuntimeException('Parameter "kernel.project_dir" must be a string.');
        }

        $bundleConfigDir = dirname(__DIR__, 3) . '/config/workflow';
        $projectConfigDir = rtrim($projectDir, '/\\') . '/config/store-assembler/workflow';

        $loader = new WorkflowDefinitionLoader();
        $definitions = $loader->load([$bundleConfigDir, $projectConfigDir]);
        if ($definitions === []) {
            throw new \RuntimeException(sprintf('No workflow definitions found in %s or %s.', $bundleConfigDir, $projectConfigDir));
        }

        $stepIds = [];
        $preparedDefinitions = [];

        foreach ($definitions as $platform => $definition) {
            $source = $definition['source'] ?? 'n/a';

            foreach ($definition['prepare'] ?? [] as $serviceId) {
                $this->assertServiceImplements($container, $serviceId, PrepareStepInterface::class, $platform, $source);
                $stepIds[] = $serviceId;
            }

            foreach ($definition['install'] ?? [] as $serviceId) {
                $this->assertServiceImplements($container, $serviceId, InstallStepInterface::class, $platform, $source);
                $stepIds[] = $serviceId;
            }

            $preparedDefinitions[$platform] = [
                'prepare' => $definition['prepare'] ?? [],
                'install' => $definition['install'] ?? [],
            ];
        }

        $container->setParameter('sylius_store_assembler.workflow.definitions', $preparedDefinitions);

        $uniqueStepIds = array_values(array_unique($stepIds));
        $references = [];
        foreach ($uniqueStepIds as $serviceId) {
            $references[$serviceId] = new Reference($serviceId);
        }

        if ($references === []) {
            throw new \RuntimeException('Workflow definitions do not declare any steps.');
        }

        $locatorId = ServiceLocatorTagPass::register($container, $references);
        $container->setAlias('sylius_store_assembler.plugin.workflow.step_locator', (string) $locatorId)->setPublic(false);
    }

    private function assertServiceImplements(
        ContainerBuilder $container,
        string $serviceId,
        string $expectedInterface,
        string $platform,
        string $source
    ): void {
        if (!$container->has($serviceId)) {
            $message = sprintf(
                'Workflow step "%s" referenced for platform "%s" in %s is not defined in the container.',
                $serviceId,
                $platform,
                $source
            );

            throw new \RuntimeException($message);
        }

        $definition = $container->findDefinition($serviceId);
        $class = $definition->getClass();
        if ($class === null) {
            $message = sprintf(
                'Workflow step service "%s" must have an explicit class to validate interface implementation.',
                $serviceId
            );

            throw new \RuntimeException($message);
        }

        $class = $container->getParameterBag()->resolveValue($class);
        if (!is_string($class) || !class_exists($class)) {
            $message = sprintf('Unable to resolve class for workflow step "%s".', $serviceId);
            throw new \RuntimeException($message);
        }

        if (!is_a($class, $expectedInterface, true)) {
            $message = sprintf(
                'Workflow step "%s" (%s) must implement %s (referenced in %s).',
                $serviceId,
                $class,
                $expectedInterface,
                $source
            );

            throw new \RuntimeException($message);
        }
    }
}
