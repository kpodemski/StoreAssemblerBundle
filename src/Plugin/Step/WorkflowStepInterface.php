<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;

/** @experimental */
interface WorkflowStepInterface
{
    public function run(StepContext $context): void;
}
