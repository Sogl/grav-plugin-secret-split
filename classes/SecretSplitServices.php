<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Closure;
use Grav\Common\Grav;

final class SecretSplitServices
{
    private ?SecretSplitYamlHelper $yamlHelper = null;

    private ?SecretSplitCatalogBuilder $catalogBuilder = null;

    private ?SecretSplitPathResolver $paths = null;

    private ?SecretSplitStorageManager $storageManager = null;

    private ?SecretSplitStateManager $stateManager = null;

    private ?SecretSplitAdminFlow $adminFlow = null;

    private ?SecretSplitContext $context = null;

    private ?SecretSplitI18n $i18n = null;

    private ?SecretSplitMutationService $mutation = null;

    private ?SecretSplitApplicationService $application = null;

    public function __construct(
        private readonly Grav $grav,
        private readonly string $userDir,
        private readonly Closure $logDebug,
        private readonly Closure $getProtectedFieldCatalog,
        private readonly Closure $collectBlueprintFieldOrder
    ) {}

    /**
     * Shared wiring — used by the plugin on Grav 1.7 and by the api
     * controller on Grav 2, so both admin surfaces hit the same services.
     * When no log callback is given, debug output follows the plugin's own
     * `debug_logging` setting.
     */
    public static function create(Grav $grav, ?callable $logDebug = null): self
    {
        if ($logDebug === null) {
            $logDebug = static function (string $message, array $context = []) use ($grav): void {
                if (!(bool) $grav['config']->get('plugins.secret-split.debug_logging', false)) {
                    return;
                }
                $logger = $grav['log'] ?? null;
                if (is_object($logger)) {
                    $logger->debug('[secret-split] ' . $message, $context);
                }
            };
        }

        $services = null;
        $services = new self(
            $grav,
            USER_DIR,
            $logDebug(...),
            Closure::fromCallable([SecretSplitPlugin::class, 'getProtectedFieldCatalog']),
            function (string $pluginSlug) use (&$services): array {
                $pluginDir = USER_DIR . 'plugins/' . $pluginSlug;
                if (!is_dir($pluginDir)) {
                    return [];
                }

                $prefix = 'plugins.' . $pluginSlug . '.';

                return array_values(array_map(
                    static fn(string $fullKey): string => str_starts_with($fullKey, $prefix)
                        ? substr($fullKey, strlen($prefix))
                        : $fullKey,
                    array_keys($services->catalogBuilder()->collectConfigFieldsForPlugin($pluginDir, $pluginSlug))
                ));
            }
        );

        return $services;
    }

    /**
     * Which secrets file a freshly-extracted value lands in — same precedence
     * the plugin uses on Grav 1.7 saves: env override, then env's own file if
     * the key already lives there, then base, defaulting to env when the key
     * is new.
     */
    public function resolveStorageTarget(
        string $fullKey,
        array $baseSecrets,
        array $envSecrets,
        bool $hasEnvStorage,
        string $preferredScope = ''
    ): string {
        if ($preferredScope === 'base') {
            return 'base';
        }

        if ($preferredScope === 'env') {
            return $this->paths()->getEnvironmentStoragePath() !== '' ? 'env' : 'base';
        }

        if (!$hasEnvStorage) {
            return 'base';
        }

        if ($this->yamlHelper()->hasByDotPath($envSecrets, $fullKey)) {
            return 'env';
        }

        if ($this->yamlHelper()->hasByDotPath($baseSecrets, $fullKey)) {
            return 'base';
        }

        return 'env';
    }

    /**
     * Apply the admin-next environment selection (X-Config-Environment /
     * X-Grav-Environment headers) to the path resolver so state, extraction
     * and migrate/return operate on the scope the operator picked. Without a
     * header the booted environment stands — Grav 1.7 admin, front-end and
     * CLI requests are unaffected. Pass the dispatched request when one is
     * available (api controller); the plugin entry points use the shared
     * `request` service, which carries the same client headers.
     */
    public function applyRequestEnvironment(?object $request = null): void
    {
        $override = $this->adminFlow()->getRequestEnvironmentOverride($request);
        if ($override !== null) {
            $this->paths()->setEnvironmentOverride($override);
        }
    }

    public function yamlHelper(): SecretSplitYamlHelper
    {
        if ($this->yamlHelper === null) {
            $this->yamlHelper = new SecretSplitYamlHelper();
        }

        return $this->yamlHelper;
    }

    public function catalogBuilder(): SecretSplitCatalogBuilder
    {
        if ($this->catalogBuilder === null) {
            $this->catalogBuilder = new SecretSplitCatalogBuilder([SecretSplitI18n::class, 'translateStatic']);
        }

        return $this->catalogBuilder;
    }

    public function paths(): SecretSplitPathResolver
    {
        if ($this->paths === null) {
            $this->paths = new SecretSplitPathResolver($this->grav, $this->userDir);
        }

        return $this->paths;
    }

    public function storageManager(): SecretSplitStorageManager
    {
        if ($this->storageManager === null) {
            $this->storageManager = new SecretSplitStorageManager(
                $this->paths(),
                $this->yamlHelper(),
                $this->collectBlueprintFieldOrder
            );
        }

        return $this->storageManager;
    }

    public function stateManager(): SecretSplitStateManager
    {
        if ($this->stateManager === null) {
            $this->stateManager = new SecretSplitStateManager($this->storageManager());
        }

        return $this->stateManager;
    }

    public function context(): SecretSplitContext
    {
        if ($this->context === null) {
            $this->context = new SecretSplitContext(
                $this->paths(),
                $this->getProtectedFieldCatalog
            );
        }

        return $this->context;
    }

    public function adminFlow(): SecretSplitAdminFlow
    {
        if ($this->adminFlow === null) {
            $this->adminFlow = new SecretSplitAdminFlow(
                $this->grav,
                $this->userDir,
                $this->yamlHelper(),
                $this->logDebug,
                $this->context()
            );
        }

        return $this->adminFlow;
    }

    public function i18n(): SecretSplitI18n
    {
        if ($this->i18n === null) {
            $this->i18n = new SecretSplitI18n($this->grav, $this->paths());
        }

        return $this->i18n;
    }

    public function mutation(): SecretSplitMutationService
    {
        if ($this->mutation === null) {
            $this->mutation = new SecretSplitMutationService($this->yamlHelper());
        }

        return $this->mutation;
    }

    public function application(): SecretSplitApplicationService
    {
        if ($this->application === null) {
            $this->application = new SecretSplitApplicationService(
                $this->paths(),
                $this->storageManager(),
                $this->stateManager(),
                $this->mutation(),
                $this->i18n()
            );
        }

        return $this->application;
    }
}
