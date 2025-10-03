<?php

declare(strict_types=1);

namespace Sylius\StoreAssemblerBundle\Plugin;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** @experimental */
final class B2BKitSupport
{
    private const ELASTIC_CONFIG_IMPORT = '@BitBagSyliusElasticsearchPlugin/config/config.yml';
    private const ELASTIC_ROUTE_NAME = 'bitbag_sylius_elasticsearch_plugin';
    private const ELASTIC_ROUTE_RESOURCE = '@BitBagSyliusElasticsearchPlugin/config/routing.yml';

    private Filesystem $filesystem;

    public function __construct(private readonly string $projectDir)
    {
        $this->filesystem = new Filesystem();
    }

    public function configure(SymfonyStyle $io, OutputInterface $output): void
    {
        $io->section('Configuring Sylius B2B Kit Elasticsearch integration');

        $this->ensureSyliusConfig();
        $this->ensureShopRoutes();
        $this->cleanupFosElasticaConfig();
        $this->publishProductVariant();
        $this->cleanupMainRoutes();
        $rectorUpdated = $this->updateRectorConfig($io);
        $rectorRan = $this->runRector($io, $output);
        $this->ensureProductRepository($io);
        $this->remindPostInstall($io, $rectorUpdated, $rectorRan);

        $io->success('B2B Kit integration steps completed.');
    }

    private function ensureSyliusConfig(): void
    {
        $path = $this->projectDir . '/config/packages/_sylius.yaml';
        if (!is_file($path)) {
            return;
        }

        $data = Yaml::parseFile($path) ?? [];
        $imports = $data['imports'] ?? [];

        foreach ($imports as $import) {
            if (is_array($import) && ($import['resource'] ?? null) === self::ELASTIC_CONFIG_IMPORT) {
                return;
            }
        }

        array_unshift($imports, ['resource' => self::ELASTIC_CONFIG_IMPORT]);
        $data['imports'] = $imports;
        file_put_contents($path, Yaml::dump($data, 10));
    }

    private function ensureShopRoutes(): void
    {
        $path = $this->projectDir . '/config/routes/sylius_shop.yaml';
        if (!is_file($path)) {
            return;
        }

        $routes = Yaml::parseFile($path) ?? [];
        if (isset($routes[self::ELASTIC_ROUTE_NAME])) {
            return;
        }

        $updated = [];
        $inserted = false;

        foreach ($routes as $name => $config) {
            if (!$inserted && $name === 'sylius_shop') {
                $updated[self::ELASTIC_ROUTE_NAME] = ['resource' => self::ELASTIC_ROUTE_RESOURCE];
                $inserted = true;
            }

            $updated[$name] = $config;
        }

        if (!$inserted) {
            $updated[self::ELASTIC_ROUTE_NAME] = ['resource' => self::ELASTIC_ROUTE_RESOURCE];
        }

        file_put_contents($path, Yaml::dump($updated, 10));
    }

    private function cleanupFosElasticaConfig(): void
    {
        $path = $this->projectDir . '/config/packages/fos_elastica.yaml';
        if (!is_file($path)) {
            return;
        }

        $config = Yaml::parseFile($path) ?? [];
        if (!isset($config['fos_elastica']['indexes'])) {
            return;
        }

        unset($config['fos_elastica']['indexes']);
        file_put_contents($path, Yaml::dump($config, 10));
    }

    private function publishProductVariant(): void
    {
        $path = $this->projectDir . '/src/Entity/Product/ProductVariant.php';
        $dir = dirname($path);

        if (!is_dir($dir)) {
            $this->filesystem->mkdir($dir);
        }

        if (!is_file($path)) {
            // Create a minimal, compatible entity keeping room for future traits/interfaces
            $skeleton = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Entity\Product;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ProductVariant as BaseProductVariant;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_product_variant')]
class ProductVariant extends BaseProductVariant
{
    protected function createTranslation(): ProductVariantTranslationInterface
    {
        return new ProductVariantTranslation();
    }
}
PHP;
            file_put_contents($path, $skeleton);
        }

        $content = (string) file_get_contents($path);

        $content = $this->ensureElasticsearchVariantUses($content);
        $content = $this->ensureElasticsearchVariantInterface($content);
        $content = $this->ensureElasticsearchVariantTrait($content);

        file_put_contents($path, $content);
    }

    private function ensureElasticsearchVariantUses(string $content): string
    {
        $useInterface = 'use BitBag\\SyliusElasticsearchPlugin\\Model\\ProductVariantInterface as BitBagElasticsearchPluginVariantInterface;';
        $useTrait = 'use BitBag\\SyliusElasticsearchPlugin\\Model\\ProductVariantTrait as BitBagElasticsearchPluginVariantTrait;';

        if (str_contains($content, $useInterface) && str_contains($content, $useTrait)) {
            return $content;
        }

        // Determine class position to avoid inserting inside class body
        $classPos = null;
        if (preg_match('/^\s*(?:final\s+)?class\s+ProductVariant\b/m', $content, $m, PREG_OFFSET_CAPTURE)) {
            $classPos = $m[0][1];
        }

        // Find last top-level use (before class)
        $lastUseEnd = null;
        if (preg_match_all('/^use\s+[^;]+;\s*\n/m', $content, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $match) {
                $pos = $match[1];
                $end = $pos + strlen($match[0]);
                if ($classPos === null || $end < $classPos) {
                    $lastUseEnd = $end;
                }
            }
        }

        $injected = '';
        if (!str_contains($content, $useInterface)) {
            $injected .= $useInterface . "\n";
        }
        if (!str_contains($content, $useTrait)) {
            $injected .= $useTrait . "\n";
        }

        if ($lastUseEnd !== null) {
            return substr($content, 0, $lastUseEnd) . $injected . substr($content, $lastUseEnd);
        }

        // If there are no use statements, insert after namespace
        if (preg_match('/^\s*namespace\s+[^;]+;\s*\n/m', $content, $ns, PREG_OFFSET_CAPTURE)) {
            $insertionPoint = $ns[0][1] + strlen($ns[0][0]);
            return substr($content, 0, $insertionPoint) . $injected . substr($content, $insertionPoint);
        }

        // Fallback: just after opening tag
        if (preg_match('/^<\?php\s*\n/m', $content, $op, PREG_OFFSET_CAPTURE)) {
            $insertionPoint = $op[0][1] + strlen($op[0][0]);
            return substr($content, 0, $insertionPoint) . $injected . substr($content, $insertionPoint);
        }

        return $injected . $content;
    }

    private function ensureElasticsearchVariantInterface(string $content): string
    {
        // Ensure class implements BitBagElasticsearchPluginVariantInterface (aliased in use)
        $classPattern = '/class\s+ProductVariant\s+extends\s+[^\s]+\s*(implements\s+([^\{]+))?/m';

        if (!preg_match($classPattern, $content, $m)) {
            return $content; // unexpected, leave as is
        }

        $implementsBlock = $m[1] ?? '';
        $fullMatch = $m[0];

        if ($implementsBlock === '') {
            $replacement = rtrim($fullMatch) . ' implements BitBagElasticsearchPluginVariantInterface';
            return str_replace($fullMatch, $replacement, $content);
        }

        if (str_contains($implementsBlock, 'BitBagElasticsearchPluginVariantInterface')) {
            return $content;
        }

        // Insert into implements list
        $newFull = preg_replace_callback(
            '/implements\s+([^\{]+)/m',
            static function (array $mm): string {
                $list = trim($mm[1]);
                if ($list === '') {
                    return 'implements BitBagElasticsearchPluginVariantInterface ';
                }
                // Keep existing list, append our interface
                return 'implements ' . rtrim($list) . ', BitBagElasticsearchPluginVariantInterface ';
            },
            $fullMatch,
            1
        );

        return str_replace($fullMatch, $newFull, $content);
    }

    private function ensureElasticsearchVariantTrait(string $content): string
    {
        if (str_contains($content, 'use BitBagElasticsearchPluginVariantTrait;')) {
            return $content;
        }

        // Insert the trait as the first statement inside the class body
        $pattern = '/(class\s+ProductVariant[^\{]*\{)(\s*)/m';
        if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            $insertAt = $m[0][1] + strlen($m[1][0]);
            $injected = "\n    use BitBagElasticsearchPluginVariantTrait;\n\n";
            return substr($content, 0, $insertAt) . $injected . substr($content, $insertAt);
        }

        return $content;
    }

    private function updateRectorConfig(SymfonyStyle $io): bool
    {
        $path = $this->projectDir . '/rector.php';
        if (!is_file($path)) {
            $io->note('Skipping Rector configuration update — rector.php not found.');

            return false;
        }

        $content = (string) file_get_contents($path);
        $modified = false;

        if (!str_contains($content, 'use Sylius\\SyliusRector\\Set\\SyliusPlus;')) {
            if (str_contains($content, 'use Rector\\Config\\RectorConfig;')) {
                $content = str_replace(
                    'use Rector\\Config\\RectorConfig;',
                    "use Rector\\Config\\RectorConfig;
use Sylius\\SyliusRector\\Set\\SyliusPlus;",
                    $content,
                    $count
                );

                if ($count === 0) {
                    $content = preg_replace('/<\\?php\\s*/', "<?php

use Sylius\\SyliusRector\\Set\\SyliusPlus;
", $content, 1);
                }
            } else {
                $content = preg_replace('/<\\?php\\s*/', "<?php

use Sylius\\SyliusRector\\Set\\SyliusPlus;
", $content, 1);
            }

            $modified = true;
        }

        if (!str_contains($content, 'SyliusPlus::B2B_SUITE')) {
            $content = preg_replace(
                '/return static function \(RectorConfig \$rectorConfig\): void \{\n/',
                "return static function (RectorConfig \$rectorConfig): void {
    \$rectorConfig->sets([
        SyliusPlus::B2B_SUITE,
        SyliusPlus::B2B_SUITE_21,
    ]);

",
                $content,
                1,
                $countSets
            );

            if ($countSets === 0) {
                $io->warning('Could not automatically inject SyliusPlus Rector sets. Please update rector.php manually.');
            } else {
                $modified = true;
            }
        } elseif (!str_contains($content, 'SyliusPlus::B2B_SUITE_21')) {
            $content = preg_replace(
                '/SyliusPlus::B2B_SUITE,?/',
                "SyliusPlus::B2B_SUITE,
        SyliusPlus::B2B_SUITE_21,",
                $content,
                1,
                $countSuite21
            );

            if ($countSuite21 === 0) {
                $io->warning('Could not append SyliusPlus::B2B_SUITE_21 automatically.');
            } else {
                $modified = true;
            }
        }

        if ($modified) {
            file_put_contents($path, $content);
            $io->text(' • rector.php updated with SyliusPlus sets.');
        } else {
            $io->text(' • Rector configuration already includes SyliusPlus sets.');
        }

        return $modified;
    }

    private function runRector(SymfonyStyle $io, OutputInterface $output): bool
    {
        $binary = $this->projectDir . '/vendor/bin/rector';
        if (!is_file($binary)) {
            $io->note('Skipping Rector run — vendor/bin/rector not found.');

            return false;
        }

        $io->text(' • Running Rector (serial mode)');

        $process = new Process([$binary, 'process', '--no-progress-bar'], $this->projectDir);
        $process->setTimeout(0);

        try {
            $process->mustRun(static fn ($type, $buffer) => $output->write($buffer));
        } catch (ProcessFailedException $exception) {
            $exitCode = $exception->getProcess()->getExitCode();
            $io->warning(sprintf(
                'Rector exited with code %d. Please rerun: %s',
                $exitCode ?? -1,
                'vendor/bin/rector process --no-progress-bar'
            ));

            return false;
        }

        return true;
    }

    private function ensureProductRepository(SymfonyStyle $io): void
    {
        $path = $this->projectDir . '/src/Repository/ProductRepository.php';
        if (is_file($path)) {
            $io->note('ProductRepository already exists — ensure it integrates Sylius B2B filtering.');

            return;
        }

        $this->filesystem->mkdir(dirname($path));

        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Sylius\B2BKit\Checker\ProductVisibilityFilteringCheckerInterface;
use Sylius\B2BKit\Doctrine\ORM\CreateProductQueryBuilderTrait;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository as BaseProductRepository;
use Sylius\Component\Customer\Context\CustomerContextInterface;

class ProductRepository extends BaseProductRepository
{
    use CreateProductQueryBuilderTrait;

    public function __construct(
        EntityManagerInterface $entityManager,
        ClassMetadata $class,
        protected ProductVisibilityFilteringCheckerInterface $productVisibilityFilteringChecker,
        protected CustomerContextInterface $customerContext,
    ) {
        parent::__construct($entityManager, $class);
    }

    public function createQueryBuilder($alias, $indexBy = null): QueryBuilder
    {
        return $this->createFilteredQueryBuilder(
            parent::createQueryBuilder($alias, $indexBy),
        );
    }
}
PHP;

        file_put_contents($path, $content);
        $io->text(' • Created src/Repository/ProductRepository.php with B2B filters.');
    }

    private function remindPostInstall(SymfonyStyle $io, bool $rectorUpdated, bool $rectorSucceeded): void
    {
        $io->note('Run doctrine:migrations:migrate, assets:install, yarn build, and optionally fixtures or Elasticsearch population.');

        if ($rectorUpdated) {
            $io->text(' • SyliusPlus Rector sets have been added to rector.php.');
        }

        if (!$rectorSucceeded) {
            $io->warning('Rector did not finish successfully. Rerun `vendor/bin/rector process --no-progress-bar`.');
        }
    }

    private function cleanupMainRoutes(): void
    {
        $path = $this->projectDir . '/config/routes.yaml';
        if (!is_file($path)) {
            return;
        }

        $routes = Yaml::parseFile($path) ?? [];
        if (!isset($routes[self::ELASTIC_ROUTE_NAME])) {
            return;
        }

        unset($routes[self::ELASTIC_ROUTE_NAME]);
        file_put_contents($path, Yaml::dump($routes, 10));
    }
}
