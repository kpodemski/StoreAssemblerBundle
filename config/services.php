<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Sylius\StoreAssemblerBundle\MessageHandler\InstallPluginMessageHandler;
use Sylius\StoreAssemblerBundle\Service\InstallationStateManager;
use Sylius\StoreAssemblerBundle\Command\FixtureLoadCommand;
use Sylius\StoreAssemblerBundle\Command\FixturePrepareCommand;
use Sylius\StoreAssemblerBundle\Command\PluginInstallCommand;
use Sylius\StoreAssemblerBundle\Command\PluginInstallInteractiveCommand;
use Sylius\StoreAssemblerBundle\Command\PluginListCommand;
use Sylius\StoreAssemblerBundle\Command\PluginPrepareCommand;
use Sylius\StoreAssemblerBundle\Command\ThemePrepareCommand;
use Sylius\StoreAssemblerBundle\Configurator\YamlNodeConfigurator;
use Sylius\StoreAssemblerBundle\Plugin\B2BKitSupport;
use Sylius\StoreAssemblerBundle\Plugin\PluginCatalog;
use Sylius\StoreAssemblerBundle\Plugin\PluginWorkflow;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

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
        ->set('sylius_store_assembler.plugin.workflow', PluginWorkflow::class)
        ->args([
            '%kernel.project_dir%',
            service('sylius_store_assembler.plugin.catalog'),
            service('sylius_store_assembler.plugin.b2bkit_support'),
        ])
    ;

    $services
        ->set('sylius_store_assembler.command.plugin_prepare', PluginPrepareCommand::class)
        ->args([
            '%kernel.project_dir%',
            service('sylius_store_assembler.plugin.workflow'),
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.command.plugin_install', PluginInstallCommand::class)
        ->args([
            '%kernel.project_dir%',
            service('sylius_store_assembler.plugin.workflow'),
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.command.plugin_list', PluginListCommand::class)
        ->args([
            service('sylius_store_assembler.plugin.catalog'),
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.command.plugin_install_public', PluginInstallInteractiveCommand::class)
        ->args([
            service('sylius_store_assembler.plugin.catalog'),
            service('sylius_store_assembler.plugin.workflow'),
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.command.fixture_prepare', FixturePrepareCommand::class)
        ->args([
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.command.fixture_load', FixtureLoadCommand::class)
        ->args([
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

    $services
        ->set('sylius_store_assembler.command.theme_prepare', ThemePrepareCommand::class)
        ->args([
            '%kernel.project_dir%',
        ])
        ->tag('console.command')
    ;

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
