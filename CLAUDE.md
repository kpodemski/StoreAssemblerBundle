# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Quality Assurance
```bash
# Code style fixing
composer cs
# Static analysis
composer phpstan
# Rector refactoring
composer rector
```

### Main Store Assembler Script
```bash
# Full build and deploy
./bin/sylius-store-assembler

# Build only (plugins, fixtures, themes)
./bin/sylius-store-assembler --build

# Deploy only (DB operations, fixtures loading)
./bin/sylius-store-assembler --deploy

# Download store preset
./bin/sylius-store-assembler --get-preset <preset-name>
```

### Plugin Management
```bash
# Interactive plugin installation
php bin/console sylius:plugins:install

# Install specific plugin
php bin/console sylius:plugins:install sylius/adyen-plugin --plugin-version=2.0

# List available plugins
php bin/console sylius:store-assembler:plugin:list
```

## Architecture Overview

### Core Components

**Sylius Store Assembler** is a Symfony bundle that automates Sylius e-commerce store configuration using two main approaches:

### 1. Store Preset Approach (Primary)
- **Configuration**: `store-preset/store-preset.json` - main configuration file defining plugins, themes, and fixtures
- **Structure**:
  ```
  store-preset/
  ├── store-preset.json    # Main config: plugins, themes, fixtures
  ├── fixtures/fixtures.yaml
  └── themes/
  ```
- **Plugin Workflow**:
  1. `sylius:store-assembler:plugin:prepare` - Composer installation, stability config
  2. `sylius:store-assembler:plugin:install` - Manifest processing, configurators

### 2. CLI Interactive Approach (Alternative)
- Direct command execution: `sylius:plugins:install <package>`
- Same underlying workflow as store preset approach
- Interactive plugin selection from catalog

### Plugin Manifest System

Each plugin requires a manifest at `config/plugins/{vendor}/{name}/{version}/manifest.json`:

```json
{
  "type": "community|commercial",
  "minimum-stability": "dev",
  "rector-sets": ["Sylius\\SyliusRector\\Set\\..."],
  "steps": ["shell command"],
  "configurators": [{"class": "ConfiguratorClass"}]
}
```

### Key Classes

**Domain-Driven Architecture:**

- **Plugin Domain** (`src/Plugin/`):
  - `PluginWorkflowOrchestrator` - Step-based workflow orchestration
  - `PluginCatalog` - Plugin discovery from manifest files
  - `PluginDefinition` - Plugin metadata container
  - `Command/` - Public CLI commands (InstallInteractiveCommand, ListCommand, etc.)
  - `Step/` - Individual workflow steps (14 steps implementing PrepareStepInterface/InstallStepInterface)
  - `Service/` - Helper services (PluginDefinitionResolver, ComposerMetadataResolver)
  - `Workflow/` - Workflow infrastructure (orchestrator, registry, loader, context)

- **StorePreset Domain** (`src/StorePreset/`):
  - `ConfigurationProviderInterface` - Configuration abstraction
  - `Provider/StorePresetConfigurationProvider` - Reads from store-preset.json

- **Fixture Domain** (`src/Fixture/`):
  - `Command/PrepareCommand` - Fixture preparation
  - `Command/LoadCommand` - Fixture loading

- **Theme Domain** (`src/Theme/`):
  - `Command/PrepareCommand` - Theme asset copying and configuration

### Workflow Architecture

**Step-Based Orchestration** (YAML-configured):

Workflow definitions in `config/workflow/{platform}.yaml`:
```yaml
platform: platform_sh
prepare:
  - sylius_store_assembler.plugin.step.adjust_composer_stability
  - sylius_store_assembler.plugin.step.configure_composer_repository
  - sylius_store_assembler.plugin.step.install_community_plugins
  - sylius_store_assembler.plugin.step.install_commercial_plugins
  - sylius_store_assembler.plugin.step.process_rector_config
install:
  - sylius_store_assembler.plugin.step.validate_manifests
  - sylius_store_assembler.plugin.step.execute_shell_commands
  - sylius_store_assembler.plugin.step.run_configurators
```

**Key Components:**
- `WorkflowDefinitionLoader` - Loads YAML workflow definitions
- `WorkflowDefinitionRegistry` - Platform-specific workflow registry
- `WorkflowDefinitionPass` - Compiler pass validating step services
- `StepContext` - Shared data container passed between steps
- Service locator for dynamic step resolution

### Store Assembly Process

The full store assembly follows this sequence:

1. **BUILD Phase**:
   - Plugin preparation (Composer install)
   - Plugin installation (manifest processing)
   - Fixture preparation
   - Theme preparation

2. **DEPLOY Phase**:
   - Database recreation
   - Schema updates
   - Fixture loading

### Bundle Structure (Domain-Driven)

```
src/
├── Plugin/                          # Plugin installation domain
│   ├── Command/                     # Public CLI commands
│   │   ├── InstallCommand.php
│   │   ├── InstallInteractiveCommand.php
│   │   ├── ListCommand.php
│   │   └── PrepareCommand.php
│   ├── Message/                     # Async messages
│   │   └── InstallPluginMessage.php
│   ├── MessageHandler/              # Async handlers
│   │   └── InstallPluginMessageHandler.php
│   ├── Service/                     # Domain services
│   │   ├── ComposerMetadataResolver.php
│   │   ├── InstallationStateManager.php
│   │   └── PluginDefinitionResolver.php
│   ├── Step/                        # Workflow steps (14 steps)
│   │   ├── PrepareStepInterface.php
│   │   ├── InstallStepInterface.php
│   │   ├── AbstractPluginDefinitionsStep.php
│   │   └── ...individual steps...
│   ├── Workflow/                    # Workflow orchestration
│   │   ├── PluginWorkflowOrchestrator.php
│   │   ├── WorkflowDefinitionLoader.php
│   │   ├── WorkflowDefinitionRegistry.php
│   │   ├── StepContext.php
│   │   └── WorkflowPhase.php
│   ├── B2BKitSupport.php
│   ├── PluginCatalog.php
│   └── PluginDefinition.php
├── StorePreset/                     # Configuration domain
│   ├── Provider/
│   │   └── StorePresetConfigurationProvider.php
│   └── ConfigurationProviderInterface.php
├── Fixture/                         # Fixtures domain
│   └── Command/
│       ├── LoadCommand.php
│       └── PrepareCommand.php
├── Theme/                           # Theme domain
│   └── Command/
│       └── PrepareCommand.php
├── Configurator/                    # Plugin configurators
│   └── YamlNodeConfigurator.php
├── DependencyInjection/
│   └── Compiler/
│       └── WorkflowDefinitionPass.php
└── Util/
    └── ManifestLocator.php
```

### Development Notes

- All classes marked `@experimental` - API may change
- Uses Symfony Process component for shell command execution
- Supports both community and commercial plugins with repository authentication
- Integrates with Rector for automated code updates
- Messenger integration for async plugin installation

## Architecture Benefits

### Step-Based Workflow System

The implemented step-based architecture provides:

- **Single Responsibility**: Each step handles one specific task (e.g., AdjustComposerStabilityStep, InstallPaidPluginsStep)
- **YAML Configuration**: Workflows defined in `config/workflow/{platform}.yaml` - easy to customize per deployment platform
- **Validation**: Compiler pass validates all step service IDs at container compile time
- **Extensibility**: Add new steps without modifying existing code - just update YAML
- **Debugging**: Clear boundaries for error identification - each step is isolated
- **Reusability**: Steps can be shared across different workflow configurations
- **Testability**: Easy to mock and unit test individual steps

### Configuration Provider Pattern

Replaced `ConfigTrait` anti-pattern with proper dependency injection:

```php
interface ConfigurationProviderInterface
{
    public function getPlugins(): array;
    public function getThemes(): array;
    public function getFixturesFilePath(): string;
    public function getFixturesSuiteName(): string;
}
```

Benefits:
- Clean separation of concerns
- Easy to add alternative providers (DatabaseConfigurationProvider, YamlConfigurationProvider)
- Proper dependency injection - commands receive provider via constructor
- Testable - mock the interface in tests

### Workflow Step Interfaces

Three-tier interface hierarchy:

1. `WorkflowStepInterface` - Base interface with `getName()` and `execute()`
2. `PrepareStepInterface extends WorkflowStepInterface` - For prepare phase steps
3. `InstallStepInterface extends WorkflowStepInterface` - For install phase steps

This allows type-safe step validation at compile time via `WorkflowDefinitionPass`.