<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Workflow;

use Psr\Container\ContainerInterface;
use Sylius\StoreAssemblerBundle\Plugin\Step\InstallStepInterface;
use Sylius\StoreAssemblerBundle\Plugin\Step\PrepareStepInterface;
use Sylius\StoreAssemblerBundle\Plugin\Step\WorkflowStepInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** @experimental */
final class PluginWorkflowOrchestrator
{
    public function __construct(
        private readonly WorkflowDefinitionRegistry $registry,
        private readonly ContainerInterface $stepLocator,
        private readonly string $projectDir,
        private readonly string $defaultPlatform = 'platform_sh',
    ) {
    }

    /**
     * @param array<string, string> $plugins
     */
    public function prepare(SymfonyStyle $io, OutputInterface $output, array $plugins, ?string $platform = null): void
    {
        if ($plugins === []) {
            $io->warning('No plugins provided. Nothing to prepare.');

            return;
        }

        $definition = $this->definition($platform);
        $context = new StepContext($io, $output, $plugins, $this->projectDir);
        $this->runSteps($definition->stepsFor(WorkflowPhase::PREPARE), WorkflowPhase::PREPARE, $context);

        $io->success('[Plugin Preparer] All plugins installed successfully.');
    }

    /**
     * @param array<string, string> $plugins
     */
    public function install(SymfonyStyle $io, OutputInterface $output, array $plugins, ?string $platform = null): void
    {
        if ($plugins === []) {
            $io->warning('No plugins provided. Nothing to install.');

            return;
        }

        $definition = $this->definition($platform);
        $context = new StepContext($io, $output, $plugins, $this->projectDir);
        $this->runSteps($definition->stepsFor(WorkflowPhase::INSTALL), WorkflowPhase::INSTALL, $context);
    }

    /**
     * @param list<string> $steps
     */
    private function runSteps(array $steps, WorkflowPhase $phase, StepContext $context): void
    {
        foreach ($steps as $serviceId) {
            if (!$this->stepLocator->has($serviceId)) {
                throw new \RuntimeException(sprintf('Workflow step service "%s" is not registered.', $serviceId));
            }

            $step = $this->stepLocator->get($serviceId);
            if (!$step instanceof WorkflowStepInterface) {
                throw new \RuntimeException(sprintf('Service "%s" must implement WorkflowStepInterface.', $serviceId));
            }

            if ($phase === WorkflowPhase::PREPARE && !$step instanceof PrepareStepInterface) {
                throw new \RuntimeException(sprintf('Service "%s" must implement PrepareStepInterface.', $serviceId));
            }

            if ($phase === WorkflowPhase::INSTALL && !$step instanceof InstallStepInterface) {
                throw new \RuntimeException(sprintf('Service "%s" must implement InstallStepInterface.', $serviceId));
            }

            $step->run($context);
        }
    }

    private function definition(?string $platform): WorkflowDefinition
    {
        $target = $platform ?? $this->defaultPlatform;

        return $this->registry->get($target);
    }
}
