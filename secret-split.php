<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Closure;
use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Common\Data\Data;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Utils;
use RocketTheme\Toolbox\Event\Event;

class SecretSplitPlugin extends Plugin
{
    /** @var array<string,mixed>|null */
    private static $fieldCatalog = null;

    /** @var array<string,string>|null */
    private $jsTranslations = null;

    private ?SecretSplitServices $services = null;

    private ?string $pendingSecretSplitAction = null;

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    private function callback(string $method): Closure
    {
        return Closure::fromCallable([$this, $method]);
    }

    private function getServices(): SecretSplitServices
    {
        if ($this->services === null) {
            $this->services = new SecretSplitServices(
                $this->grav,
                USER_DIR,
                $this->callback('logDebug'),
                Closure::fromCallable([self::class, 'getProtectedFieldCatalog']),
                function (string $pluginSlug): array {
                    $pluginDir = USER_DIR . 'plugins/' . $pluginSlug;
                    if (!is_dir($pluginDir)) {
                        return [];
                    }

                    $prefix = 'plugins.' . $pluginSlug . '.';

                    return array_values(array_map(
                        static fn(string $fullKey): string => str_starts_with($fullKey, $prefix)
                            ? substr($fullKey, strlen($prefix))
                            : $fullKey,
                        array_keys($this->getServices()->catalogBuilder()->collectConfigFieldsForPlugin($pluginDir, $pluginSlug))
                    ));
                }
            );
        }

        return $this->services;
    }

    private function getPathResolver(): SecretSplitPathResolver
    {
        return $this->getServices()->paths();
    }

    private function getYamlHelper(): SecretSplitYamlHelper
    {
        return $this->getServices()->yamlHelper();
    }

    private function getAdminFlow(): SecretSplitAdminFlow
    {
        return $this->getServices()->adminFlow();
    }

    private function getContextService(): SecretSplitContext
    {
        return $this->getServices()->context();
    }

    private function getI18nService(): SecretSplitI18n
    {
        return $this->getServices()->i18n();
    }

    private function getMutationService(): SecretSplitMutationService
    {
        return $this->getServices()->mutation();
    }

    private function getApplicationService(): SecretSplitApplicationService
    {
        return $this->getServices()->application();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [['onPluginsInitialized', 2000]],
        ];
    }

    public function onPluginsInitialized(): void
    {
        $this->logDebug('onPluginsInitialized', [
            'is_admin' => $this->isAdmin(),
            'uri' => (string) (($this->grav['uri']->route() ?? '')),
        ]);

        $this->applySecretOverlay();

        $this->logDebug('enabling admin hooks');
        $this->enable([
            'onAdminThemeInitialized' => ['onAdminThemeInitialized', 100],
            'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
            'onAdminSave' => ['onAdminSave', 0],
            'onAdminAfterSave' => ['onAdminAfterSave', 0],
            'onAssetsInitialized' => ['onAssetsInitialized', 0],
        ]);
    }

    public function onAssetsInitialized(): void
    {
        $admin = $this->grav['admin'] ?? null;
        $uri = $this->grav['uri'] ?? null;
        if (!is_object($admin) || !is_object($uri)) {
            return;
        }

        $route = (string) ($uri->route() ?? '');
        $isSecretSplitRoute = str_contains($route, '/secret-split');
        if (!$isSecretSplitRoute) {
            return;
        }

        $this->logDebug('secret-split assets start', ['route' => $route]);
        $stateCatalog = $this->getProtectedFieldStateCatalog();
        $this->logDebug('secret-split state catalog ready', ['counts' => $stateCatalog['counts'] ?? []]);
        if ($isSecretSplitRoute) {
            $catalog = self::getProtectedFieldCatalog();
            $this->logDebug('secret-split catalog ready', ['plugins' => count($catalog['plugins'] ?? []), 'fields' => count($catalog['fields'] ?? [])]);
            $this->grav['assets']->addInlineJs(
                'window.SecretSplitFieldCatalog = ' . json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
                ['group' => 'bottom', 'priority' => 98]
            );
        }
        $this->grav['assets']->addInlineJs(
            'window.SecretSplitFieldStates = ' . json_encode($stateCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
            ['group' => 'bottom', 'priority' => 99]
        );
        $this->grav['assets']->addInlineJs(
            'window.SecretSplitI18n = ' . json_encode($this->getJsTranslations(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';',
            ['group' => 'bottom', 'priority' => 99]
        );
        $css = 'plugin://secret-split/assets/admin/secret-split-admin.css';
        $js = 'plugin://secret-split/assets/admin/secret-split-admin.js';
        $cssVersioned = $css . '?v=' . $this->assetVersion($css);
        $jsVersioned = $js . '?v=' . $this->assetVersion($js);

        $this->grav['assets']->addCss($cssVersioned, [
            'priority' => 99,
        ]);
        $this->grav['assets']->addJs($jsVersioned, [
            'group' => 'bottom',
            'loading' => 'defer',
            'priority' => 100,
        ]);
        $this->logDebug('secret-split assets queued', ['route' => $route]);
    }

    public function onAdminTaskExecute(Event $event): void
    {
        $method = strtolower((string) ($event['method'] ?? ''));
        $this->logDebug('admin task execute', ['method' => $method]);
        $task = match ($method) {
            'taskmigratesecretsplit', 'tasktaskmigratesecretsplit' => 'migrate',
            'taskreturnsecretsplit', 'tasktaskreturnsecretsplit' => 'return',
            default => null,
        };
        if ($task === null) {
            return;
        }

        $controller = $event['controller'] ?? null;
        if (!is_object($controller) || !method_exists($controller, 'authorizeTask')) {
            return;
        }

        if (!$controller->authorizeTask($task . ' secret split', ['admin.plugins', 'admin.super'])) {
            return;
        }

        $async = $this->isAsyncSecretSplitTaskRequest();

        $uri = $this->grav['uri'] ?? null;
        $nonce = '';
        if ($uri && method_exists($uri, 'param')) {
            $nonce = (string) ($uri->param('admin-nonce') ?: $uri->query('admin-nonce') ?: '');
        }
        if ($nonce === '') {
            $nonce = (string) ($_REQUEST['admin-nonce'] ?? $_REQUEST['nonce'] ?? '');
        }
        if ($nonce === '' || !Utils::verifyNonce($nonce, 'admin-form')) {
            if ($async) {
                $this->sendSecretSplitJson([
                    'ok' => false,
                    'scope' => 'error',
                    'message' => $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.INVALID_TOKEN'),
                ]);
            }
            $this->grav['admin']->setMessage($this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.INVALID_TOKEN'), 'error');
            $this->grav->redirect($this->getSecretSplitAdminRoute());
            return;
        }

        try {
            if ($async) {
                $this->persistSecretSplitConfigFromRequest();
            }

            if ($task === 'migrate') {
                $summary = $this->migrateProtectedValues();
                $duplicates = $summary['normalized'] > 0
                    ? $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.DUPLICATE_SUFFIX', [
                        '%normalized%' => (string) $summary['normalized'],
                    ])
                    : '';
                $message = $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.MIGRATED', [
                    '%migrated%' => (string) $summary['migrated'],
                    '%duplicates%' => $duplicates,
                ]);
            } else {
                $summary = $this->returnProtectedValuesToTrackedConfig();
                $message = $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.RETURNED', [
                    '%returned%' => (string) $summary['returned'],
                ]);
            }
        } catch (\Throwable $e) {
            $message = $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.ACTION_FAILED', [
                '%error%' => $e->getMessage(),
            ]);
            $this->logDebug('secret split task failed', [
                'task' => $task,
                'async' => $async,
                'error' => $e->getMessage(),
            ]);

            if ($async) {
                $this->sendSecretSplitJson([
                    'ok' => false,
                    'scope' => 'error',
                    'message' => $message,
                    'state' => $this->getProtectedFieldStateCatalog(),
                ]);
            }

            $this->grav['admin']->setMessage($message, 'error');
            $this->grav->redirect($this->getSecretSplitAdminRoute());
            return;
        }

        if ($async) {
            $this->sendSecretSplitJson([
                'ok' => true,
                'scope' => 'info',
                'message' => $message,
                'state' => $this->getProtectedFieldStateCatalog(),
            ]);
        }

        $this->grav['admin']->setMessage($message, 'info');
        $this->grav->redirect($this->getSecretSplitAdminRoute());
    }

    public static function getProtectedPluginOptions(): array
    {
        $catalog = self::getProtectedFieldCatalog();

        return $catalog['plugins'] ?? [];
    }

    public static function getProtectedFieldOptions(): array
    {
        $catalog = self::getProtectedFieldCatalog();

        return $catalog['fields'] ?? [];
    }

    public function onAdminThemeInitialized(): void
    {
        $post = $this->getAdminRequestBody();

        $this->logDebug('flex configure probe', [
            'task' => $post['task'] ?? null,
            'has_data' => array_key_exists('data', $post),
        ]);

        if (($post['task'] ?? null) !== 'configure' || !array_key_exists('data', $post)) {
            return;
        }

        [$nonce, $nonceAction] = $this->getAdminFormNonceFromRequest($post);
        if ($nonce === '' || $nonceAction === '' || !Utils::verifyNonce($nonce, $nonceAction)) {
            $this->logDebug('flex configure skipped due to invalid nonce');
            return;
        }

        $pluginSlug = $this->getFlexConfiguredPluginSlugFromRoute();
        $this->logDebug('flex configure route resolved', [
            'plugin' => $pluginSlug,
        ]);
        if ($pluginSlug === null) {
            return;
        }

        $protectedKeys = $this->getProtectedKeysForPlugin($pluginSlug);
        if ($protectedKeys === []) {
            return;
        }

        $this->logDebug('flex configure secrets deferred until tracked config changes on disk', [
            'plugin' => $pluginSlug,
        ]);
        $this->registerFlexPostSaveMigration($pluginSlug);
    }

    public function onAdminSave(Event $event): void
    {
        $object = $event['object'] ?? null;
        if (!$object instanceof Data) {
            return;
        }

        $storage = $object->file();
        if (!$storage) {
            return;
        }

        $filePath = $storage->filename();
        if (!preg_match('~(?:^|/)plugins/([^/]+)\.yaml$~', $filePath, $matches)) {
            return;
        }

        $pluginSlug = $matches[1];
        if ($pluginSlug === 'secret-split') {
            $this->deleteSecretsForRemovedProtectedFields($filePath, $object);
            $this->pendingSecretSplitAction = $this->getPendingSecretSplitActionFromRequest();
        }

        $protectedKeys = $this->getProtectedKeysForPlugin($pluginSlug);
        $this->logDebug('admin save probe', [
            'plugin' => $pluginSlug,
            'protected_keys' => $protectedKeys,
        ]);
        if ($protectedKeys === []) {
            return;
        }

        $this->extractProtectedValuesForPlugin(
            $pluginSlug,
            $object,
            $this->inferTrackedScopeFromConfigPath($filePath)
        );
    }

    public function onAdminAfterSave(Event $event): void
    {
        $object = $event['object'] ?? null;
        if (!$object instanceof Data) {
            return;
        }

        $storage = $object->file();
        if (!$storage) {
            return;
        }

        $filePath = $storage->filename();
        if (!preg_match('~(?:^|/)plugins/([^/]+)\.yaml$~', $filePath, $matches)) {
            return;
        }

        if (($matches[1] ?? '') !== 'secret-split') {
            return;
        }

        $action = $this->pendingSecretSplitAction ?? $this->getPendingSecretSplitActionFromRequest();
        $this->pendingSecretSplitAction = null;
        if ($action === null) {
            return;
        }

        $config = $this->grav['config'] ?? null;
        if ($config) {
            $savedConfig = method_exists($object, 'toArray') ? $object->toArray() : null;
            if (!is_array($savedConfig)) {
                $savedConfig = $this->getYamlHelper()->loadYamlFile($filePath);
            }

            $config->set('plugins.secret-split', is_array($savedConfig) ? $savedConfig : []);
            $this->config = $config;
        }

        try {
            if ($action === 'migrate') {
                $summary = $this->migrateProtectedValues();
                $duplicates = $summary['normalized'] > 0
                    ? $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.DUPLICATE_SUFFIX', [
                        '%normalized%' => (string) $summary['normalized'],
                    ])
                    : '';
                $message = $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.MIGRATED', [
                    '%migrated%' => (string) $summary['migrated'],
                    '%duplicates%' => $duplicates,
                ]);
            } else {
                $summary = $this->returnProtectedValuesToTrackedConfig();
                $message = $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.RETURNED', [
                    '%returned%' => (string) $summary['returned'],
                ]);
            }

            $this->grav['admin']->setMessage($message, 'info');
        } catch (\Throwable $e) {
            $message = $this->translate('PLUGIN_SECRET_SPLIT.MESSAGES.ACTION_FAILED', [
                '%error%' => $e->getMessage(),
            ]);
            $this->logDebug('secret split save action failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            $this->grav['admin']->setMessage($message, 'error');
        }
    }

    private function applySecretOverlay(): void
    {
        $config = $this->config ?: ($this->grav['config'] ?? null);
        if (!$config) {
            return;
        }

        $this->getMutationService()->applySecretOverlay(
            $config,
            $this->getBaseStoragePath(),
            $this->getEnvironmentStoragePath()
        );
    }

    private function getProtectedKeysForPlugin(string $pluginSlug): array
    {
        return $this->getMutationService()->getProtectedKeysForPlugin($pluginSlug, $this->getProtectedDefinitions());
    }

    private function assetVersion(string $locator): int
    {
        $path = $this->grav['locator']->findResource($locator, true, true);
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return 0;
        }

        return (int) (filemtime($path) ?: 0);
    }

    private function getFlexConfiguredPluginSlugFromRoute(): ?string
    {
        $admin = $this->grav['admin'] ?? null;
        $flex = $this->grav['flex_objects'] ?? null;
        if (!is_object($admin) || !method_exists($admin, 'getRouteDetails') || !is_object($flex) || !method_exists($flex, 'getDirectories')) {
            return null;
        }

        [, $location, $target] = $admin->getRouteDetails();
        $target = is_string($target) ? urldecode($target) : null;
        $path = '/' . ($target ? $location . '/' . $target : $location) . '/';
        $this->logDebug('resolving flex route', [
            'location' => $location,
            'target' => $target,
            'path' => $path,
        ]);

        foreach ($flex->getDirectories() as $directory) {
            if (!is_object($directory) || !method_exists($directory, 'getConfig')) {
                continue;
            }

            $configurePath = $directory->getConfig('admin.router.actions.configure.path');
            $this->logDebug('checking directory configure path', [
                'flex_type' => method_exists($directory, 'getFlexType') ? $directory->getFlexType() : null,
                'configure_path' => $configurePath,
            ]);
            if (!is_string($configurePath) || rtrim($configurePath, '/') . '/' !== $path) {
                continue;
            }

            $configFile = (string) ($directory->getConfig('blueprints.configure.file') ?? '');
            $storageFolder = (string) ($directory->getConfig('data.storage.options.folder') ?? '');
            $this->logDebug('checking directory config files', [
                'flex_type' => method_exists($directory, 'getFlexType') ? $directory->getFlexType() : null,
                'blueprint_config_file' => $configFile,
                'storage_folder' => $storageFolder,
            ]);

            $source = $configFile !== '' ? $configFile : $storageFolder;
            if ($source !== '' && preg_match('~(?:^|/|://)plugins/([^/]+)\.yaml$~', $source, $matches)) {
                return $matches[1];
            }

            return null;
        }

        return null;
    }

    private function extractProtectedValuesForPlugin(string $pluginSlug, Data|array &$source, string $preferredScope = ''): void
    {
        $this->getMutationService()->extractProtectedValuesForPlugin(
            $pluginSlug,
            $source,
            $this->getProtectedDefinitions(),
            $this->getSubmittedPluginDataFromRequest(),
            $this->getBaseStoragePath(),
            $this->getEnvironmentStoragePath(),
            $this->callback('isPasswordKey'),
            $this->callback('resolveStorageTarget'),
            $this->callback('logDebug'),
            $preferredScope
        );
    }

    private function isPasswordKey(string $fullKey): bool
    {
        return $this->getContextService()->isPasswordKey($fullKey);
    }

    /**
     * @return array<int,array{full_key:string,password:bool}>
     */
    private function getProtectedDefinitions(): array
    {
        return $this->getContextService()->getProtectedDefinitions();
    }

    private function resolveStorageTarget(
        string $fullKey,
        array $baseSecrets,
        array $envSecrets,
        bool $hasEnvStorage,
        string $preferredScope = ''
    ): string
    {
        if ($preferredScope === 'env') {
            return $this->getEnvironmentStoragePath() !== '' ? 'env' : 'base';
        }

        if (!$hasEnvStorage) {
            return 'base';
        }

        if ($this->getYamlHelper()->hasByDotPath($envSecrets, $fullKey)) {
            return 'env';
        }

        if ($this->getYamlHelper()->hasByDotPath($baseSecrets, $fullKey)) {
            return 'base';
        }

        return 'env';
    }

    private function inferTrackedScopeFromConfigPath(string $filePath): string
    {
        if (preg_match('~(?:^|/)env/[^/]+/config/plugins/[^/]+\.yaml$~', $filePath)) {
            return 'env';
        }

        return '';
    }

    private function getAdminFormNonceFromRequest(?array $post = null): array
    {
        return $this->getAdminFlow()->getAdminFormNonceFromRequest();
    }

    private function getAdminRequestBody(): array
    {
        return $this->getAdminFlow()->getAdminRequestBody();
    }

    private function isAsyncSecretSplitTaskRequest(): bool
    {
        return $this->getAdminFlow()->isAsyncSecretSplitTaskRequest();
    }

    private function getPendingSecretSplitActionFromRequest(): ?string
    {
        return $this->getAdminFlow()->getPendingSecretSplitActionFromRequest();
    }

    private function getSubmittedPluginDataFromRequest(): array
    {
        return $this->getAdminFlow()->getSubmittedPluginDataFromRequest();
    }

    private function getPluginConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->getPathResolver()->getPluginConfigValue($key, $default);
    }

    private function getEnvironmentName(): string
    {
        return $this->getPathResolver()->getEnvironmentName();
    }

    private function getBaseStoragePath(): string
    {
        return $this->getPathResolver()->getBaseStoragePath();
    }

    private function getEnvironmentStoragePath(): string
    {
        return $this->getPathResolver()->getEnvironmentStoragePath();
    }

    private function deleteSecretsForRemovedProtectedFields(string $filePath, Data $object): void
    {
        $previousDefinitions = $this->getContextService()->getProtectedDefinitionsFromConfigArray(
            $this->getYamlHelper()->loadYamlFile($filePath)
        );
        $currentDefinitions = $this->getContextService()->getProtectedDefinitionsFromSavedObject($object);
        $this->getAdminFlow()->deleteSecretsForRemovedDefinitions(
            $previousDefinitions,
            $currentDefinitions,
            $this->getBaseStoragePath(),
            $this->getEnvironmentStoragePath()
        );
    }

    private function persistSecretSplitConfigFromRequest(): void
    {
        $this->getAdminFlow()->persistSecretSplitConfigFromRequest(
            $this->callback('getBaseStoragePath'),
            $this->callback('getEnvironmentStoragePath'),
            function (array $currentConfig): void {
                $config = $this->grav['config'] ?? null;
                if ($config) {
                    $config->set('plugins.secret-split', $currentConfig);
                    $this->config = $config;
                }
            }
        );
    }

    private function sendSecretSplitJson(array $payload): never
    {
        $this->getAdminFlow()->sendJson($payload);
    }

    /**
     * @return array{plugins: array<string,string>, fields: array<string,string>, fieldPlugins: array<string,string>, passwordFields: string[]}
     */
    public static function getProtectedFieldCatalog(): array
    {
        if (self::$fieldCatalog !== null) {
            return self::$fieldCatalog;
        }

        $pluginRoot = USER_DIR . 'plugins';
        $catalog = [
            'plugins' => [],
            'fields' => [],
            'fieldPlugins' => [],
            'passwordFields' => [],
        ];

        $builder = new SecretSplitCatalogBuilder([SecretSplitI18n::class, 'translateStatic']);

        foreach (glob($pluginRoot . '/*', GLOB_ONLYDIR) ?: [] as $pluginDir) {
            $pluginSlug = basename($pluginDir);
            if ($pluginSlug === 'secret-split') {
                continue;
            }

            $pluginName = $builder->readPluginDisplayName($pluginDir, $pluginSlug);
            $fieldMap = $builder->collectConfigFieldsForPlugin($pluginDir, $pluginSlug);
            if ($fieldMap === []) {
                continue;
            }

            $catalog['plugins'][$pluginSlug] = $pluginName;

            foreach ($fieldMap as $fullKey => $fieldInfo) {
                $catalog['fields'][$fullKey] = $fieldInfo['label'];
                $catalog['fieldPlugins'][$fullKey] = $pluginSlug;
                if ($fieldInfo['type'] === 'password') {
                    $catalog['passwordFields'][] = $fullKey;
                }
            }
        }

        asort($catalog['plugins']);
        asort($catalog['fields']);
        $catalog['passwordFields'] = array_values(array_unique($catalog['passwordFields']));

        self::$fieldCatalog = $catalog;

        return $catalog;
    }

    private function getSecretSplitAdminRoute(): string
    {
        return $this->getAdminBaseRoute() . '/plugins/secret-split';
    }

    private function getAdminBaseRoute(): string
    {
        $admin = $this->grav['admin'] ?? null;
        $base = is_object($admin) && isset($admin->base) ? (string) $admin->base : '/admin';

        return rtrim($base, '/');
    }

    private function getMigrateTaskUrl(): string
    {
        $uri = $this->grav['uri'] ?? null;
        $route = $this->getAdminBaseRoute() . '/task:migrateSecretSplit';

        if ($uri && method_exists($uri, 'addNonce')) {
            return $uri->addNonce($route, 'admin-form', 'admin-nonce');
        }

        return Uri::addNonce($route, 'admin-form', 'admin-nonce');
    }

    private function getReturnTaskUrl(): string
    {
        $uri = $this->grav['uri'] ?? null;
        $route = $this->getAdminBaseRoute() . '/task:returnSecretSplit';

        if ($uri && method_exists($uri, 'addNonce')) {
            return $uri->addNonce($route, 'admin-form', 'admin-nonce');
        }

        return Uri::addNonce($route, 'admin-form', 'admin-nonce');
    }

    /**
     * @return array{
     *   fields: array<string,array{status:string,label:string,source:string}>,
     *   facts: array<string,array{
     *     status:string,
     *     label:string,
     *     source:string,
     *     secret_exists:bool,
     *     tracked_exists:bool,
     *     secret_scope:string,
     *     tracked_scope:string
     *   }>,
     *   counts: array<string,int>,
     *   actions: array<string,string>,
     *   meta: array{env_storage_available:bool,base_storage_file:string,env_storage_file:string}
     * }
     */
    private function getProtectedFieldStateCatalog(): array
    {
        return $this->getApplicationService()->buildProtectedFieldStateCatalog(
            self::getProtectedFieldCatalog()['fields'] ?? [],
            $this->getProtectedDefinitions(),
            $this->getMigrateTaskUrl(),
            $this->getReturnTaskUrl(),
            $this->callback('translate')
        );
    }

    /**
     * @return array{migrated:int,normalized:int,missing:int}
     */
    private function migrateProtectedValues(): array
    {
        return $this->getApplicationService()->migrateProtectedValues(
            $this->getProtectedDefinitions(),
            $this->callback('resolveStorageTarget'),
            $this->callback('logDebug')
        );
    }

    /**
     * @return array{returned:int,missing:int}
     */
    private function returnProtectedValuesToTrackedConfig(): array
    {
        return $this->getApplicationService()->returnProtectedValuesToTrackedConfig($this->getProtectedDefinitions());
    }

    private function logDebug(string $message, array $context = []): void
    {
        if (!(bool) $this->getPluginConfigValue('debug_logging', false)) {
            return;
        }

        $logger = $this->grav['log'] ?? null;
        if (!$logger) {
            return;
        }

        $logger->debug('[secret-split] ' . $message, $context);
    }

    private function translate(string $key, array $replacements = []): string
    {
        return $this->getI18nService()->translate($key, $replacements);
    }

    /**
     * @param string[]|null $languages
     */
    private static function translateStatic(string $key, ?array $languages = null): string
    {
        return SecretSplitI18n::translateStatic($key, $languages);
    }

    /**
     * @return array<string,string>
     */
    private function getJsTranslations(): array
    {
        if (!is_array($this->jsTranslations)) {
            $this->jsTranslations = $this->getI18nService()->getJsTranslations();
        }

        return $this->jsTranslations;
    }

    private function registerFlexPostSaveMigration(string $pluginSlug): void
    {
        $snapshots = $this->getApplicationService()->snapshotTrackedConfig($pluginSlug);

        register_shutdown_function(function () use ($pluginSlug, $snapshots): void {
            try {
                $changedScopes = $this->getApplicationService()->detectChangedTrackedScopes($pluginSlug, $snapshots);

                if ($changedScopes === []) {
                    $this->logDebug('skipped flex post-save migration because tracked config did not change', [
                        'plugin' => $pluginSlug,
                    ]);
                    return;
                }

                $summary = $this->getApplicationService()->processFlexTrackedConfigMigration(
                    $pluginSlug,
                    $this->getProtectedDefinitions(),
                    $changedScopes,
                    $this->callback('isPasswordKey'),
                    $this->callback('resolveStorageTarget'),
                    $this->callback('logDebug')
                );
                $this->logDebug('completed flex post-save migration', [
                    'plugin' => $pluginSlug,
                    'changed_scopes' => $changedScopes,
                    'summary' => $summary,
                ]);
            } catch (\Throwable $e) {
                $this->logDebug('flex post-save migration failed', [
                    'plugin' => $pluginSlug,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $this->logDebug('registered flex post-save migration', [
            'plugin' => $pluginSlug,
        ]);
    }

}
