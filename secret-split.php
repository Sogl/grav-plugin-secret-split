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

    private static bool $earlyCatalogDependenciesLoaded = false;

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    private function callback(string $method): Closure
    {
        return Closure::fromCallable([$this, $method]);
    }

    private static function ensureEarlyCatalogDependenciesLoaded(): void
    {
        if (self::$earlyCatalogDependenciesLoaded) {
            return;
        }

        require_once __DIR__ . '/classes/SecretSplitCatalogBuilder.php';
        require_once __DIR__ . '/classes/SecretSplitI18n.php';

        self::$earlyCatalogDependenciesLoaded = true;
    }

    private function getServices(): SecretSplitServices
    {
        if ($this->services === null) {
            $this->services = SecretSplitServices::create($this->grav, $this->callback('logDebug'));
            $this->services->applyRequestEnvironment();
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
            // Grav 2 / admin2 surface — these events only exist where the api
            // plugin runs, so a 1.7 install simply never fires them.
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
        ];
    }

    /**
     * Swap the `.field_key` select for our web-component field — admin-next
     * only, so admin1 keeps its plain `select`. The component filters options
     * by the row's chosen plugin, reproducing the admin1 JS behaviour.
     */
    public function onApiBlueprintResolved(Event $event): void
    {
        $fields = $event['fields'] ?? null;
        if (!is_array($fields)) {
            return;
        }
        $this->swapFieldSelectType($fields);
        $event['fields'] = $fields;
    }

    /**
     * @param array<string,mixed> $fields
     */
    private function swapFieldSelectType(array &$fields): void
    {
        foreach ($fields as &$field) {
            if (!is_array($field)) {
                continue;
            }
            if (($field['type'] ?? '') === 'select'
                && str_contains((string) ($field['classes'] ?? ''), 'secret-split-field-select')
            ) {
                $field['type'] = 'secret-split-field';
            }
            // The admin1 overview placeholder renders nothing under admin-next
            // (display fields drop raw HTML), so swap it for the custom field
            // component that paints the status tiles + actions in the same spot.
            if (($field['type'] ?? '') === 'display'
                && str_contains((string) ($field['content'] ?? ''), 'secret-split-overview')
            ) {
                $field['type'] = 'secret-split-overview';
                unset($field['content']);
            }
            if (isset($field['fields']) && is_array($field['fields'])) {
                $this->swapFieldSelectType($field['fields']);
            }
        }
        unset($field);
    }

    /**
     * Admin Next API endpoints — the SPA page uses them for state, marking
     * persistence and the migrate/return actions that lived behind admin1
     * task URLs.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = \Grav\Plugin\SecretSplit\Api\SecretSplitApiController::class;

        $routes->get('/secret-split/state', [$controller, 'state']);
        $routes->post('/secret-split/fields', [$controller, 'saveFields']);
        $routes->post('/secret-split/migrate', [$controller, 'migrate']);
        $routes->post('/secret-split/return', [$controller, 'returnSecrets']);
    }

    /**
     * Sidebar entry — an optional convenience shortcut that lands on the
     * plugin's settings form. Toggleable via the admin_sidebar_item option;
     * admin1 never had one either, so disabling is a clean 1.7-parity mode.
     */
    public function onApiSidebarItems(Event $event): void
    {
        $config = $this->grav['config'] ?? null;
        if ($config && !$config->get('plugins.secret-split.admin_sidebar_item', true)) {
            return;
        }

        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => 'secret-split',
            'plugin'    => 'secret-split',
            'label'     => 'Secret Split',
            'icon'      => 'fa-lock',
            'route'     => '/plugins/secret-split',
            'priority'  => 0,
            'authorize' => 'admin.super',
        ];
        $event['items'] = $items;
    }

    /**
     * Page definition — blueprint mode bound to the same config endpoints the
     * settings page uses, so the legacy /plugin/secret-split URL renders the
     * SAME form with the same data instead of a 404 or an empty shell. The
     * overview and field-status pieces live inside that form as custom field
     * types, exactly where admin1 injected them.
     */
    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'secret-split') {
            return;
        }
        $event['definition'] = [
            'id'            => 'secret-split',
            'plugin'        => 'secret-split',
            'title'         => 'Secret Split',
            'icon'          => 'fa-lock',
            'page_type'     => 'blueprint',
            'blueprint'     => 'secret-split',
            'data_endpoint' => '/config/plugins/secret-split',
            'save_endpoint' => '/config/plugins/secret-split',
        ];
    }

    public function onPluginsInitialized(): void
    {
        $this->logDebug('onPluginsInitialized', [
            'is_admin' => $this->isAdmin(),
            'uri' => (string) (($this->grav['uri']->route() ?? '')),
        ]);

        $this->applySecretOverlay();

        if ($this->getAdminFlow()->isApiRequest()) {
            $this->registerApiSaveWatchers();
        }

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
            $this->inferTrackedScopeFromConfigPath($filePath),
            $filePath
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
            $this->getEnvironmentStoragePath(),
            $this->getProtectedDefinitions()
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

    private function extractProtectedValuesForPlugin(string $pluginSlug, Data|array &$source, string $preferredScope = '', string $trackedFilePath = ''): void
    {
        $isApiRequest = $this->getAdminFlow()->isApiRequest();
        // onAdminSave fires before the api writes the file, so the target
        // still holds the pre-save tracked values Admin Next rendered.
        $previousTracked = $isApiRequest && $trackedFilePath !== '' && is_file($trackedFilePath)
            ? $this->getYamlHelper()->loadYamlFile($trackedFilePath)
            : null;

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
            $preferredScope,
            $isApiRequest,
            $previousTracked,
            $this->callback('getCatalogFieldDefaultInfo')
        );
    }

    private function getCatalogFieldDefaultInfo(string $fullKey): array
    {
        return $this->getContextService()->getCatalogFieldDefaultInfo($fullKey);
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
        return $this->getServices()->resolveStorageTarget(
            $fullKey,
            $baseSecrets,
            $envSecrets,
            $hasEnvStorage,
            $preferredScope
        );
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
     * @return array{plugins: array<string,string>, fields: array<string,string>, fieldPlugins: array<string,string>, passwordFields: string[], defaults: array<string,mixed>}
     */
    public static function getProtectedFieldCatalog(): array
    {
        if (self::$fieldCatalog !== null) {
            return self::$fieldCatalog;
        }

        self::ensureEarlyCatalogDependenciesLoaded();

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
                if (array_key_exists('default', $fieldInfo)) {
                    $catalog['defaults'][$fullKey] = $fieldInfo['default'];
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

    /**
     * Plugin-specific admin-next endpoints (e.g. algolia-pro's
     * PATCH /api/v1/algolia-pro/data) write their own config YAML without
     * ever firing onAdminSave, so per-form interception cannot see them. The
     * snapshot + shutdown migration the Flex path uses is pipeline-agnostic:
     * on any api request we watch every protected plugin's tracked config
     * and extract whatever actually changed by request end.
     */
    private function registerApiSaveWatchers(): void
    {
        $slugs = [];
        foreach ($this->getProtectedDefinitions() as $def) {
            if (preg_match('~^plugins\.([^.]+)\.~', (string) ($def['full_key'] ?? ''), $m)) {
                $slugs[$m[1]] = true;
            }
        }
        foreach (array_keys($slugs) as $slug) {
            $this->registerFlexPostSaveMigration($slug);
        }
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
