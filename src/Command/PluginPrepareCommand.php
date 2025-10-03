<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Command;

use Sylius\StoreAssemblerBundle\Plugin\PluginWorkflow;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;

#[AsCommand(
    name: 'sylius:store-assembler:plugin:prepare',
    description: 'Configure and install Sylius plugins according to their manifest.json',
    hidden: true,
)]
/** @experimental */
class PluginPrepareCommand extends Command
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
            $this->workflow->prepare($this->io, $output, $plugins);
        } catch (ProcessFailedException $exception) {
            $this->io->error(trim($exception->getMessage()));

            return Command::FAILURE;
        } catch (\RuntimeException $exception) {
            $this->io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
