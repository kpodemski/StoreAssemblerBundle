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
use Symfony\Component\Process\Exception\ProcessFailedException;

#[AsCommand(
    name: 'sylius:store-assembler:plugin:prepare',
    description: 'Configure and install Sylius plugins according to their manifest.json',
    hidden: true,
)]
/** @experimental */
final class PrepareCommand extends Command
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

            $this->workflow->prepare($io, $output, $plugins, $platform !== null ? (string) $platform : null);
        } catch (ProcessFailedException $exception) {
            $io->error(trim($exception->getMessage()));

            return Command::FAILURE;
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
