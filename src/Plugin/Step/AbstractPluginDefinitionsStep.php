<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Plugin\PluginDefinition;
use Sylius\StoreAssemblerBundle\Plugin\Service\PluginDefinitionResolver;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;

/** @experimental */
abstract class AbstractPluginDefinitionsStep
{
    public function __construct(private readonly PluginDefinitionResolver $definitionResolver)
    {
    }

    /**
     * @return array<string, PluginDefinition>
     */
    final protected function definitions(StepContext $context): array
    {
        if ($context->has(StepContext::KEY_PLUGIN_DEFINITIONS)) {
            /** @var array<string, PluginDefinition> $definitions */
            $definitions = $context->get(StepContext::KEY_PLUGIN_DEFINITIONS);

            return $definitions;
        }

        $definitions = $this->definitionResolver->resolve($context->plugins());
        $context->set(StepContext::KEY_PLUGIN_DEFINITIONS, $definitions);

        return $definitions;
    }
}
