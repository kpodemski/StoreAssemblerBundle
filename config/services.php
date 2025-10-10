<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sylius\StoreAssemblerBundle\Configurator\YamlNodeConfigurator;
use Sylius\StoreAssemblerBundle\Fixture\Command\LoadCommand as FixtureLoadCommand;
use Sylius\StoreAssemblerBundle\Fixture\Command\PrepareCommand as FixturePrepareCommand;
use Sylius\StoreAssemblerBundle\Plugin\MessageHandler\InstallPluginMessageHandler;
use Sylius\StoreAssemblerBundle\Plugin\B2BKitSupport;
use Sylius\StoreAssemblerBundle\Plugin\Command\InstallCommand as PluginInstallCommand;
use Sylius\StoreAssemblerBundle\Plugin\Command\InstallInteractiveCommand;
use Sylius\StoreAssemblerBundle\Plugin\Command\ListCommand as PluginListCommand;
use Sylius\StoreAssemblerBundle\Plugin\Command\PrepareCommand as PluginPrepareCommand;
use Sylius\StoreAssemblerBundle\Plugin\PluginCatalog;
use Sylius\StoreAssemblerBundle\Plugin\Service\ComposerMetadataResolver;
use Sylius\StoreAssemblerBundle\Plugin\Service\PluginDefinitionResolver;
use Sylius\StoreAssemblerBundle\Plugin\Step\AdjustComposerStabilityStep;
use Sylius\StoreAssemblerBundle\Plugin\Step\ConfigureComposerRepositoryStep;
use Sylius\StoreAssemblerBundle\Plugin\Step\ExecuteShellCommandsStep;
use Sylius\StoreAssemblerBundle\Plugin\Step\InstallCommunityPluginsStep;
use Sylius\StoreAssemblerBundle\Plugin\Step\InstallCommercialPluginsStep;
use Sylius\StoreAssemblerBundle\Plugin\Step\ProcessRectorConfigStep;
use Sylius\StoreAssemblerBundle\Plugin\Step\RunConfiguratorsStep;
use Sylius\StoreAssemblerBundle\Plugin\Step\ValidateManifestsStep;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\PluginWorkflowOrchestrator;
use Sylius\StoreAssemblerBundle\Plugin\Workflow\WorkflowDefinitionRegistry;
use Sylius\StoreAssemblerBundle\Plugin\Service\InstallationStateManager;
use Sylius\StoreAssemblerBundle\StorePreset\ConfigurationProviderInterface;
use Sylius\StoreAssemblerBundle\StorePreset\Provider\StorePresetConfigurationProvider;
use Sylius\StoreAssemblerBundle\Theme\Command\PrepareCommand as ThemePrepareCommand;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $parameters = $container->parameters();
    $parameters->set('sylius_store_assembler.workflow.default_platform', 'platform_sh');
    $parameters->set('sylius_store_assembler.workflow.definitions', []);

    $services = $container->services();

    // Configuration Provider
    $services
        ->set('sylius_store_assembler.configuration_provider', StorePresetConfigurationProvider::class)
        ->args([
            '%kernel.project_dir%',
        ])
    ;

    $services->alias(ConfigurationProviderInterface::class, 'sylius_store_assembler.configuration_provider');

    // Plugin Domain
    $services
        ->set('sylius_store_assembler.plugin.catalog', PluginCatalog::class)
        ->args([
            '%kernel.project_dir%',
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.b2bkit_support', B2BKitSupport::class)
        ->args([
            '%kernel.project_dir%',
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.command.prepare', PluginPrepareCommand::class)
        ->args([
            service('sylius_store_assembler.configuration_provider'),
            service('sylius_store_assembler.plugin.workflow.orchestrator'),
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.plugin.command.install', PluginInstallCommand::class)
        ->args([
            service('sylius_store_assembler.configuration_provider'),
            service('sylius_store_assembler.plugin.workflow.orchestrator'),
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.plugin.command.list', PluginListCommand::class)
        ->args([
            service('sylius_store_assembler.plugin.catalog'),
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.plugin.command.install_interactive', InstallInteractiveCommand::class)
        ->args([
            service('sylius_store_assembler.plugin.catalog'),
            service('sylius_store_assembler.plugin.workflow.orchestrator'),
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.plugin.definition_resolver', PluginDefinitionResolver::class)
        ->args([
            service('sylius_store_assembler.plugin.catalog'),
            '%kernel.project_dir%',
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.composer_metadata_resolver', ComposerMetadataResolver::class)
    ;

    // Plugin Workflow Steps
    $services
        ->set('sylius_store_assembler.plugin.step.adjust_composer_stability', AdjustComposerStabilityStep::class)
        ->args([
            service('sylius_store_assembler.plugin.composer_metadata_resolver'),
            '%kernel.project_dir%',
            service('sylius_store_assembler.plugin.definition_resolver'),
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.step.configure_composer_repository', ConfigureComposerRepositoryStep::class)
        ->args([
            service('sylius_store_assembler.plugin.composer_metadata_resolver'),
            '%kernel.project_dir%',
            service('sylius_store_assembler.plugin.definition_resolver'),
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.step.install_community_plugins', InstallCommunityPluginsStep::class)
        ->args([
            service('sylius_store_assembler.plugin.composer_metadata_resolver'),
            '%kernel.project_dir%',
            service('sylius_store_assembler.plugin.definition_resolver'),
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.step.install_commercial_plugins', InstallCommercialPluginsStep::class)
        ->args([
            service('sylius_store_assembler.plugin.composer_metadata_resolver'),
            '%kernel.project_dir%',
            service('sylius_store_assembler.plugin.definition_resolver'),
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.step.process_rector_config', ProcessRectorConfigStep::class)
        ->args([
            '%kernel.project_dir%',
            service('sylius_store_assembler.plugin.definition_resolver'),
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.step.validate_manifests', ValidateManifestsStep::class)
        ->args([
            '%kernel.project_dir%',
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.step.execute_shell_commands', ExecuteShellCommandsStep::class)
    ;

    $services
        ->set('sylius_store_assembler.plugin.step.run_configurators', RunConfiguratorsStep::class)
        ->args([
            service('sylius_store_assembler.plugin.b2bkit_support'),
        ])
    ;

    // Plugin Workflow Orchestrator
    $services
        ->set('sylius_store_assembler.workflow.definition_registry', WorkflowDefinitionRegistry::class)
        ->args([
            param('sylius_store_assembler.workflow.definitions'),
        ])
    ;

    $services
        ->set('sylius_store_assembler.plugin.workflow.orchestrator', PluginWorkflowOrchestrator::class)
        ->args([
            service('sylius_store_assembler.workflow.definition_registry'),
            service('sylius_store_assembler.plugin.workflow.step_locator'),
            '%kernel.project_dir%',
            param('sylius_store_assembler.workflow.default_platform'),
        ])
    ;

    // Fixture Domain
    $services
        ->set('sylius_store_assembler.fixture.command.prepare', FixturePrepareCommand::class)
        ->args([
            service('sylius_store_assembler.configuration_provider'),
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.fixture.command.load', FixtureLoadCommand::class)
        ->args([
            service('sylius_store_assembler.configuration_provider'),
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    // Theme Domain
    $services
        ->set('sylius_store_assembler.theme.command.prepare', ThemePrepareCommand::class)
        ->args([
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    // Other Services
    $services
        ->set('sylius_store_assembler.configurator.yaml_node', YamlNodeConfigurator::class)
    ;

    $services
        ->set('sylius_store_assembler.service.installation_state_manager', InstallationStateManager::class)
        ->args([
            '%kernel.cache_dir%',
        ])
    ;

    $services
        ->set('sylius_store_assembler.message_handler.install_plugin', InstallPluginMessageHandler::class)
        ->args([
            service('sylius_store_assembler.service.installation_state_manager'),
            service('logger'),
            '%kernel.project_dir%',
        ])
        ->tag('messenger.message_handler')
    ;
};
