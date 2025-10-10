<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Plugin\Service\ComposerMetadataResolver;
use Sylius\StoreAssemblerBundle\Plugin\Service\PluginDefinitionResolver;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;
use Symfony\Component\Process\Process;

/** @experimental */
final class AdjustComposerStabilityStep extends AbstractPluginDefinitionsStep implements PrepareStepInterface
{
    public function __construct(
        private readonly ComposerMetadataResolver $composerMetadataResolver,
        private readonly string $projectDir,
        PluginDefinitionResolver $definitionResolver,
    ) {
        parent::__construct($definitionResolver);
    }

    public function run(StepContext $context): void
    {
        $definitions = $this->definitions($context);
        $requiredStability = $this->composerMetadataResolver->determineRequiredStability($definitions);
        if ($requiredStability === null) {
            return;
        }

        $io = $context->io();
        $io->section('[Plugin Preparer] Adjusting Composer minimum-stability');
        $io->text(sprintf(' • minimum-stability => %s (prefer-stable=true)', $requiredStability));

        (new Process(['composer', 'config', 'minimum-stability', $requiredStability], $this->projectDir))->mustRun();
        (new Process(['composer', 'config', 'prefer-stable', 'true'], $this->projectDir))->mustRun();
    }
}
