<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Command;

use Sylius\StoreAssemblerBundle\Plugin\PluginWorkflow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sylius:store-assembler:plugin:install',
    description: 'Install and configure Sylius plugins based on their manifest.json',
    hidden: true,
)]
/** @experimental */
final class PluginInstallCommand extends Command
{
    use ConfigTrait;

    private SymfonyStyle $io;

    public function __construct(
        private readonly string $projectDir,
        private readonly PluginWorkflow $workflow,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        try {
            $plugins = $this->getPlugins();
        } catch (\RuntimeException $exception) {
            $this->io->error($exception->getMessage());

            return Command::FAILURE;
        }

        try {
            $this->workflow->install($this->io, $output, $plugins);
        } catch (\RuntimeException $exception) {
            $this->io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
