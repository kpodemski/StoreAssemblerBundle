<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Workflow;

/** @experimental */
enum WorkflowPhase: string
{
    case PREPARE = 'prepare';
    case INSTALL = 'install';
}
