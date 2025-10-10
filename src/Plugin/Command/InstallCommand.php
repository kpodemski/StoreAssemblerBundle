<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Command;

use Sylius\StoreAssemblerBundle\Plugin\Workflow\PluginWorkflowOrchestrator;
use Sylius\StoreAssemblerBundle\StorePreset\ConfigurationProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sylius:store-assembler:plugin:install',
    description: 'Install and configure Sylius plugins based on their manifest.json',
    hidden: true,
)]
/** @experimental */
final class InstallCommand extends Command
{
    public function __construct(
        private readonly ConfigurationProviderInterface $configProvider,
        private readonly PluginWorkflowOrchestrator $workflow,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('platform', null, InputOption::VALUE_REQUIRED, 'Target workflow platform (optional)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $plugins = $this->configProvider->getPlugins();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        try {
            /** @var string|null $platform */
            $platform = $input->getOption('platform');

            $this->workflow->install($io, $output, $plugins, $platform !== null ? (string) $platform : null);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
