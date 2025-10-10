<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Workflow;

/** @experimental */
final class WorkflowDefinition
{
    /**
     * @param list<string> $prepareSteps
     * @param list<string> $installSteps
     */
    public function __construct(
        public readonly string $platform,
        private readonly array $prepareSteps,
        private readonly array $installSteps,
    ) {
    }

    /**
     * @return list<string>
     */
    public function stepsFor(WorkflowPhase $phase): array
    {
        return match ($phase) {
            WorkflowPhase::PREPARE => $this->prepareSteps,
            WorkflowPhase::INSTALL => $this->installSteps,
        };
    }
}
