<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;
use Symfony\Component\Process\Process;

/** @experimental */
final class ExecuteShellCommandsStep implements InstallStepInterface
{
    public function run(StepContext $context): void
    {
        /** @var array<string, string> $supported */
        $supported = $context->get(StepContext::KEY_SUPPORTED_PLUGINS, []);
        if ($supported === []) {
            return;
        }

        /** @var array<string, array{path: string, data: array<string, mixed>}> $manifests */
        $manifests = $context->get(StepContext::KEY_PLUGIN_MANIFESTS, []);
        $io = $context->io();
        $output = $context->output();
        $projectDir = $context->projectDir();

        foreach ($supported as $package => $version) {
            if (!isset($manifests[$package])) {
                continue;
            }

            $entries = $manifests[$package]['data']['steps'] ?? [];
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $command) {
                if (!is_string($command) || $command === '') {
                    continue;
                }

                $io->section('[PluginPrepare] Running shell step');
                Process::fromShellCommandline($command, $projectDir)
                    ->setTimeout(0)
                    ->run(static fn ($type, $buffer) => $output->write($buffer));
            }
        }
    }
}
