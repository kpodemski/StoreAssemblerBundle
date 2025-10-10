<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Step;

use Sylius\StoreAssemblerBundle\Plugin\PluginDefinition;
use Sylius\StoreAssemblerBundle\Plugin\Service\ComposerMetadataResolver;
use Sylius\StoreAssemblerBundle\Plugin\Service\PluginDefinitionResolver;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\StepContext;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/** @experimental */
final class ConfigureComposerRepositoryStep extends AbstractPluginDefinitionsStep implements PrepareStepInterface
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
        $commercial = array_filter(
            $definitions,
            static fn (PluginDefinition $definition): bool => $definition->isCommercial(),
        );

        if ($commercial === []) {
            return;
        }

        $io = $context->io();
        $projectDir = $this->projectDir;

        $io->section('[Plugin Preparer] Configuring private Sylius repository');
        (new Process(
            ['composer', 'config', 'repositories.sylius', 'composer', 'https://sylius.repo.packagist.com/sylius/'],
            $projectDir
        ))->mustRun();

        $io->section('[Plugin Preparer] Checking existing credentials');
        $usernameCheck = new Process(
            ['composer', 'config', '--auth', 'http-basic.sylius.repo.packagist.com.username'],
            $projectDir
        );
        $passwordCheck = new Process(
            ['composer', 'config', '--auth', 'http-basic.sylius.repo.packagist.com.password'],
            $projectDir
        );

        $usernameCheck->run();
        $passwordCheck->run();

        $hasUsername = $usernameCheck->getExitCode() === 0 && trim($usernameCheck->getOutput()) !== '';
        $hasPassword = $passwordCheck->getExitCode() === 0 && trim($passwordCheck->getOutput()) !== '';

        if ($hasUsername && $hasPassword) {
            $io->text('✔ Found existing credentials via Composer; skipping prompt.');
        } else {
            $username = $io->ask('Sylius repo username');
            $token = $io->askHidden('Sylius repo token');

            (new Process(
                ['composer', 'config', '--auth', 'http-basic.sylius.repo.packagist.com', (string) $username, (string) $token],
                $projectDir
            ))->mustRun();
        }

        $io->section('[Plugin Preparer] Verifying access (dry‑run)');
        $stabilityFlags = $this->composerMetadataResolver->collectStabilityFlags($definitions);

        foreach ($commercial as $definition) {
            $version = $context->plugins()[$definition->package] ?? $definition->version;
            $io->text(sprintf(' 🔍 Testing %s:%s', $definition->package, $version));

            $constraint = "{$definition->package}:^{$version}";
            if (isset($stabilityFlags[$definition->package])) {
                $constraint .= '@' . $stabilityFlags[$definition->package];
            }

            try {
                (new Process(
                    ['composer', 'require', $constraint, '--no-scripts', '--no-interaction', '--dry-run'],
                    $projectDir
                ))
                    ->setTimeout(0)
                    ->mustRun();
            } catch (ProcessFailedException $exception) {
                $message = sprintf(
                    'Access denied for %s:%s. Please verify your token and repository credentials.',
                    $definition->package,
                    $version
                );

                throw new \RuntimeException($message, 0, $exception);
            }
        }
    }
}
