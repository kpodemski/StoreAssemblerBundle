<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Command;

use Sylius\StoreAssemblerBundle\Plugin\PluginCatalog;
use Sylius\StoreAssemblerBundle\Plugin\PluginDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sylius:plugins:list',
    description: 'List plugins bundled with Sylius Store Assembler',
)]
final class PluginListCommand extends Command
{
    public function __construct(private readonly PluginCatalog $catalog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by type (open-source or paid)')
            ->addOption('all-versions', null, InputOption::VALUE_NONE, 'Display every available version instead of the latest per package')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $definitions = $this->catalog->all();
        if ($definitions === []) {
            $io->warning('No plugin manifests found.');

            return Command::SUCCESS;
        }

        $type = $input->getOption('type');
        if ($type !== null) {
            $normalizedType = strtolower((string) $type);
            $definitions = array_filter(
                $definitions,
                static fn (PluginDefinition $definition) => $definition->type() === $normalizedType,
            );

            if (!in_array($normalizedType, ['paid', 'open-source'], true)) {
                $io->error('Invalid type filter, use "open-source" or "paid".');

                return Command::INVALID;
            }
        }

        if (!$input->getOption('all-versions')) {
            $definitions = $this->latestPerPackage($definitions);
        }

        if ($definitions === []) {
            $io->warning('No plugins matched the provided filters.');

            return Command::SUCCESS;
        }

        $io->title('Sylius plugin catalog');
        $io->table(
            ['Package', 'Version', 'Type', 'Highlights'],
            array_map(
                fn (PluginDefinition $definition) => [
                    $definition->package,
                    $definition->version,
                    ucfirst($definition->type()),
                    $this->highlights($definition),
                ],
                $definitions
            )
        );

        $io->success(sprintf('%d plugin%s available.', count($definitions), count($definitions) === 1 ? '' : 's'));

        return Command::SUCCESS;
    }

    /**
     * @param iterable<PluginDefinition> $definitions
     *
     * @return list<PluginDefinition>
     */
    private function latestPerPackage(iterable $definitions): array
    {
        $grouped = [];

        foreach ($definitions as $definition) {
            $package = $definition->package;
            if (!isset($grouped[$package]) || $this->isVersionGreater($definition->version, $grouped[$package]->version)) {
                $grouped[$package] = $definition;
            }
        }

        ksort($grouped);

        return array_values($grouped);
    }

    private function isVersionGreater(string $left, string $right): bool
    {
        if ($this->isNumericVersion($left) && $this->isNumericVersion($right)) {
            return version_compare($left, $right, '>');
        }

        return strcmp($left, $right) > 0;
    }

    private function isNumericVersion(string $version): bool
    {
        return (bool) preg_match('/^\d+(\.\d+)*$/', $version);
    }

    private function highlights(PluginDefinition $definition): string
    {
        $manifest = $definition->manifest;
        $parts = [];

        if (!empty($manifest['steps'])) {
            $parts[] = sprintf('steps:%d', count((array) $manifest['steps']));
        }

        if (!empty($manifest['configurators'])) {
            $parts[] = sprintf('config:%d', count((array) $manifest['configurators']));
        }

        if (!empty($manifest['rector-sets'])) {
            $parts[] = sprintf('rector:%d', count((array) $manifest['rector-sets']));
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }
}
