<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Workflow;

/** @experimental */
final class WorkflowDefinitionRegistry
{
    /**
     * @var array<string, WorkflowDefinition>
     */
    private array $definitions = [];

    /**
     * @param array<string, array{prepare?: list<string>, install?: list<string>}> $rawDefinitions
     */
    public function __construct(array $rawDefinitions)
    {
        foreach ($rawDefinitions as $platform => $definition) {
            $prepare = $definition['prepare'] ?? [];
            $install = $definition['install'] ?? [];

            $this->definitions[$platform] = new WorkflowDefinition(
                $platform,
                array_values($prepare),
                array_values($install),
            );
        }
    }

    public function get(string $platform): WorkflowDefinition
    {
        if (!isset($this->definitions[$platform])) {
            $available = array_keys($this->definitions);
            $message = sprintf(
                'Workflow definition for platform "%s" not found. Available platforms: %s',
                $platform,
                $available === [] ? 'n/a' : implode(', ', $available)
            );

            throw new \InvalidArgumentException($message);
        }

        return $this->definitions[$platform];
    }

    /**
     * @return list<string>
     */
    public function platforms(): array
    {
        return array_keys($this->definitions);
    }
}
