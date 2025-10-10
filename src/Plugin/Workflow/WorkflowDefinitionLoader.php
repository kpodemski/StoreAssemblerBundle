<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin\Workflow;

use Symfony\Component\Yaml\Yaml;

/** @experimental */
final class WorkflowDefinitionLoader
{
    /**
     * @param list<string> $directories
     * @return array<string, array{prepare?: list<string>, install?: list<string>, source: string}>
     */
    public function load(array $directories): array
    {
        $definitions = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = glob(rtrim($directory, '/\\') . '/*.{yml,yaml}', GLOB_BRACE);
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                $data = Yaml::parseFile($file) ?? [];
                if (!is_array($data)) {
                    continue;
                }

                $platform = $data['platform'] ?? pathinfo($file, PATHINFO_FILENAME);
                if (!is_string($platform) || $platform === '') {
                    throw new \RuntimeException(sprintf('Invalid platform name in %s.', $file));
                }

                $prepare = $this->normalizeStepList($data['prepare'] ?? []);
                $install = $this->normalizeStepList($data['install'] ?? []);

                $definitions[$platform] = [
                    'prepare' => $prepare,
                    'install' => $install,
                    'source' => $file,
                ];
            }
        }

        return $definitions;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function normalizeStepList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new \RuntimeException('Workflow step list must be an array of service ids.');
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new \RuntimeException('Workflow step must be a non-empty string.');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }
}
