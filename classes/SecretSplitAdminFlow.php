<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Grav;

final class SecretSplitAdminFlow
{
    /** @var callable */
    private $logDebug;

    private ?array $jsonBody = null;

    public function __construct(
        private readonly Grav $grav,
        private readonly string $userDir,
        private readonly SecretSplitYamlHelper $yaml,
        callable $logDebug,
        private readonly SecretSplitContext $context
    ) {
        $this->logDebug = $logDebug;
    }

    /**
     * @return array{0:string,1:string}
     */
    public function getAdminFormNonceFromRequest(): array
    {
        $post = $this->getAdminRequestBody();

        foreach ([
            'admin-nonce' => 'admin-form',
            'form-nonce' => 'form',
            'login-nonce' => 'admin-login',
        ] as $nonceName => $nonceAction) {
            $nonce = (string) ($post[$nonceName] ?? '');
            if ($nonce !== '') {
                return [$nonce, $nonceAction];
            }
        }

        $uri = $this->grav['uri'] ?? null;
        if ($uri && method_exists($uri, 'param')) {
            foreach ([
                'admin-nonce' => 'admin-form',
                'form-nonce' => 'form',
                'login-nonce' => 'admin-login',
            ] as $nonceName => $nonceAction) {
                $nonce = (string) ($uri->param($nonceName) ?: $uri->query($nonceName) ?: '');
                if ($nonce !== '') {
                    return [$nonce, $nonceAction];
                }
            }
        }

        foreach ([
            'admin-nonce' => 'admin-form',
            'form-nonce' => 'form',
            'login-nonce' => 'admin-login',
            'nonce' => 'admin-form',
        ] as $nonceName => $nonceAction) {
            $nonce = (string) ($_REQUEST[$nonceName] ?? '');
            if ($nonce !== '') {
                return [$nonce, $nonceAction];
            }
        }

        return ['', ''];
    }

    /**
     * @return array<string,mixed>
     */
    public function getAdminRequestBody(): array
    {
        $request = $this->grav['request'] ?? null;
        if (is_object($request) && method_exists($request, 'getParsedBody')) {
            $body = $request->getParsedBody();
            if (is_array($body) && $body !== []) {
                return $body;
            }
        }

        if (is_array($_POST) && $_POST !== []) {
            return $_POST;
        }

        // Grav 2 api plugin requests carry a JSON body — getParsedBody() leaves
        // it as the raw stream. Decode php://input so submitted-data detection
        // (cleared-field semantics) keeps working on admin2 saves.
        if ($this->jsonBody === null) {
            $raw = file_get_contents('php://input');
            $this->jsonBody = self::decodeJsonRequestBody(is_string($raw) ? $raw : '');
        }

        return $this->jsonBody;
    }

    /**
     * Admin Next replays the operator's environment selection on every api
     * call via X-Config-Environment; ApiRouter::applyEnvironment() cannot
     * re-setup an already-booted container, so the selected scope is applied
     * to the path resolver instead. Mirrors ConfigController: header absent
     * -> null (keep the booted environment, i.e. classic admin / front-end /
     * CLI behavior); an empty or reserved value ('default'/'base') -> ''
     * (explicit base-only view); anything else -> the validated environment
     * name.
     */
    public function getRequestEnvironmentOverride(?object $request = null): ?string
    {
        $request = $request ?? ($this->grav['request'] ?? null);
        if (!is_object($request) || !method_exists($request, 'getHeaderLine')) {
            return null;
        }

        // Only X-Config-Environment selects the configuration scope — the
        // api plugin deliberately keeps it distinct from X-Grav-Environment,
        // which names the runtime env and must not silently pick our scope.
        $hasConfigHeader = method_exists($request, 'hasHeader')
            ? $request->hasHeader('X-Config-Environment')
            : trim((string) $request->getHeaderLine('X-Config-Environment')) !== '';
        if (!$hasConfigHeader) {
            return null;
        }

        $name = trim((string) $request->getHeaderLine('X-Config-Environment'));

        if ($name === '' || in_array(strtolower($name), ['default', 'base'], true)) {
            return '';
        }

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $name)) {
            ($this->logDebug)('request environment header rejected', ['environment' => $name]);

            return null;
        }

        return $name;
    }

    /**
     * Grav 2 api plugin requests carry X-API-Token (or Authorization: Bearer).
     * They are the only requests where a config form is built from on-disk
     * YAML rather than the overlaid runtime config, so extraction semantics
     * must treat round-tripped empty/default values differently (a stored
     * secret must not be deleted by an echo of a form that never showed it).
     */
    public function isApiRequest(?object $request = null): bool
    {
        $request = $request ?? ($this->grav['request'] ?? null);
        if (!is_object($request) || !method_exists($request, 'getHeaderLine')) {
            return false;
        }

        if (trim((string) $request->getHeaderLine('X-API-Token')) !== '') {
            return true;
        }

        if (stripos((string) $request->getHeaderLine('Authorization'), 'Bearer ') === 0) {
            return true;
        }

        // Session-authenticated admin2 requests carry no token headers — but
        // the api plugin owns its route prefix, so the path identifies them.
        $uri = method_exists($request, 'getUri') ? $request->getUri() : null;
        $path = is_object($uri) && method_exists($uri, 'getPath') ? (string) $uri->getPath() : '';
        if ($path === '') {
            return false;
        }
        $config = $this->grav['config'] ?? null;
        $base = trim((string) ($config ? $config->get('plugins.api.route', '/api') : '/api'), '/');
        $prefix = trim((string) ($config ? $config->get('plugins.api.version_prefix', 'v1') : 'v1'), '/');
        $root = '/' . $base . ($prefix !== '' ? '/' . $prefix : '');

        return $path === $root || str_starts_with($path, $root . '/');
    }

    /**
     * @return array<string,mixed>
     */
    public static function decodeJsonRequestBody(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isAsyncSecretSplitTaskRequest(): bool
    {
        $post = $this->getAdminRequestBody();
        if ((string) ($post['_secret_split_async'] ?? '') === '1') {
            return true;
        }

        $request = $this->grav['request'] ?? null;
        if (is_object($request) && method_exists($request, 'getHeaderLine')) {
            $requestedWith = strtolower((string) $request->getHeaderLine('X-Requested-With'));
            $accept = strtolower((string) $request->getHeaderLine('Accept'));

            return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
        }

        return false;
    }

    public function getPendingSecretSplitActionFromRequest(): ?string
    {
        $post = $this->getAdminRequestBody();
        $action = strtolower(trim((string) ($post['_secret_split_pending_action'] ?? '')));

        return in_array($action, ['migrate', 'return'], true) ? $action : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function getSubmittedPluginDataFromRequest(): array
    {
        $post = $this->getAdminRequestBody();
        // Admin1 wraps values in `data`; the Grav 2 api PATCH body IS the
        // config map — no envelope, so fall back to the body itself.
        $data = $post['data'] ?? (is_array($_POST) && $_POST !== [] ? [] : $post);

        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,mixed> $submittedData
     */
    public function wasSubmittedValueCleared(array $submittedData, string $relativeKey): bool
    {
        if (!$this->hasByDotPath($submittedData, $relativeKey)) {
            return false;
        }

        return $this->getByDotPath($submittedData, $relativeKey) === '';
    }

    /**
     * @param callable():string $getBaseStoragePath
     * @param callable():string $getEnvironmentStoragePath
     * @param callable(array<string,mixed>):void $updateRuntimeConfig
     */
    public function persistSecretSplitConfigFromRequest(
        callable $getBaseStoragePath,
        callable $getEnvironmentStoragePath,
        callable $updateRuntimeConfig
    ): void {
        $this->yaml->withStorageLock($getBaseStoragePath(), function () use (
            $getBaseStoragePath,
            $getEnvironmentStoragePath,
            $updateRuntimeConfig
        ): void {
            $currentConfig = $this->getSubmittedPluginDataFromRequest();
            $configPath = $this->getSecretSplitConfigPath();
            $previousConfig = $this->loadYamlFile($configPath);
            $currentConfig = $this->preserveLegacyPasswordFlags($currentConfig, $previousConfig);

            $this->doDeleteSecretsForRemovedDefinitions(
                $this->getProtectedDefinitionsFromConfigArray($previousConfig),
                $this->getProtectedDefinitionsFromConfigArray($currentConfig),
                $getBaseStoragePath(),
                $getEnvironmentStoragePath()
            );

            $this->saveYamlFile($configPath, $currentConfig);
            $updateRuntimeConfig($currentConfig);
        });
    }

    /**
     * Grav 2 api path: replace `protected_fields` inside the persisted plugin
     * config — same semantics as persistSecretSplitConfigFromRequest(), but
     * driven by decoded data rather than a form request.
     *
     * @param array<int,mixed> $protectedFields
     * @param callable():string $getBaseStoragePath
     * @param callable():string $getEnvironmentStoragePath
     * @param callable(array<string,mixed>):void $updateRuntimeConfig
     */
    public function persistSecretSplitProtectedFields(
        array $protectedFields,
        callable $getBaseStoragePath,
        callable $getEnvironmentStoragePath,
        callable $updateRuntimeConfig
    ): void {
        $this->yaml->withStorageLock($getBaseStoragePath(), function () use (
            $protectedFields,
            $getBaseStoragePath,
            $getEnvironmentStoragePath,
            $updateRuntimeConfig
        ): void {
            $configPath = $this->context->getScopedPluginConfigPath();
            $previousConfig = $this->context->getScopedPluginConfig();
            $currentConfig = $previousConfig;
            $currentConfig['protected_fields'] = $protectedFields;
            $currentConfig = $this->preserveLegacyPasswordFlags($currentConfig, $previousConfig);

            if (!$this->context->isEnvironmentScopedConfigTarget()) {
                $this->doDeleteSecretsForRemovedDefinitions(
                    $this->getProtectedDefinitionsFromConfigArray($previousConfig),
                    $this->getProtectedDefinitionsFromConfigArray($currentConfig),
                    $getBaseStoragePath(),
                    $getEnvironmentStoragePath()
                );
            } else {
                // Under an env scope, removing a field here must not delete
                // secrets that other scopes still rely on — the value stays
                // inert in its secrets file instead.
                $this->logDebug('env-scope unprotect keeps stored secrets', ['path' => $configPath]);
            }

            // Env-scope writes persist only the delta against the parent
            // config: an unchanged protected_fields list is removed from the
            // env layer so it keeps inheriting; a different list is written
            // atomically — never the merged effective view.
            if ($this->context->isEnvironmentScopedConfigTarget()) {
                $fileData = $this->context->getScopedPluginConfigRaw();
                $parentFields = $this->context->getBasePluginConfig()['protected_fields'] ?? [];
                $desiredFields = $currentConfig['protected_fields'] ?? [];
                if ($desiredFields === $parentFields) {
                    unset($fileData['protected_fields']);
                } else {
                    $fileData['protected_fields'] = $desiredFields;
                }
            } else {
                $fileData = $currentConfig;
            }

            $this->saveYamlFile($configPath, $fileData);
            $updateRuntimeConfig($currentConfig);
        });
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function sendJson(array $payload): never
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function getSecretSplitConfigPath(): string
    {
        return $this->userDir . 'config/plugins/secret-split.yaml';
    }

    /**
     * @param array<string,mixed> $currentConfig
     * @param array<string,mixed> $previousConfig
     * @return array<string,mixed>
     */
    private function preserveLegacyPasswordFlags(array $currentConfig, array $previousConfig): array
    {
        $previousPasswordFlags = [];
        foreach ($this->getProtectedDefinitionsFromConfigArray($previousConfig) as $definition) {
            if (!empty($definition['password'])) {
                $previousPasswordFlags[$definition['full_key']] = true;
            }
        }

        if ($previousPasswordFlags === []) {
            return $currentConfig;
        }

        $protectedFields = $currentConfig['protected_fields'] ?? [];
        if (!is_array($protectedFields)) {
            return $currentConfig;
        }

        foreach ($protectedFields as &$pluginEntry) {
            if (!is_array($pluginEntry) || !isset($pluginEntry['fields']) || !is_array($pluginEntry['fields'])) {
                continue;
            }

            foreach ($pluginEntry['fields'] as &$fieldEntry) {
                if (!is_array($fieldEntry)) {
                    continue;
                }

                $fullKey = trim((string) ($fieldEntry['field_key'] ?? ''));
                if ($fullKey === '' || array_key_exists('password', $fieldEntry)) {
                    continue;
                }

                if (!($previousPasswordFlags[$fullKey] ?? false) || $this->isCatalogPasswordField($fullKey)) {
                    continue;
                }

                $fieldEntry['password'] = true;
            }
            unset($fieldEntry);
        }
        unset($pluginEntry);

        $currentConfig['protected_fields'] = $protectedFields;

        return $currentConfig;
    }

    /**
     * @param array<int,array{full_key:string,password:bool}> $previousDefinitions
     * @param array<int,array{full_key:string,password:bool}> $currentDefinitions
     */
    public function deleteSecretsForRemovedDefinitions(
        array $previousDefinitions,
        array $currentDefinitions,
        string $basePath,
        string $envPath
    ): void {
        $this->yaml->withStorageLock($basePath, fn() => $this->doDeleteSecretsForRemovedDefinitions(
            $previousDefinitions,
            $currentDefinitions,
            $basePath,
            $envPath
        ));
    }

    /**
     * Callers that already hold the storage lock (persist* methods) must use
     * this directly — flock is not recursive across handles.
     */
    private function doDeleteSecretsForRemovedDefinitions(
        array $previousDefinitions,
        array $currentDefinitions,
        string $basePath,
        string $envPath
    ): void {
        $removedKeys = array_values(array_diff(
            array_column($previousDefinitions, 'full_key'),
            array_column($currentDefinitions, 'full_key')
        ));

        if ($removedKeys === []) {
            return;
        }

        $baseSecrets = $this->loadYamlFile($basePath);
        $envSecrets = $this->loadYamlFile($envPath);
        $baseDirty = false;
        $envDirty = false;

        foreach ($removedKeys as $fullKey) {
            if ($this->hasByDotPath($baseSecrets, $fullKey)) {
                $this->unsetByDotPath($baseSecrets, $fullKey);
                $baseSecrets = $this->pruneEmptyArrays($baseSecrets);
                $baseDirty = true;
            }

            if ($this->hasByDotPath($envSecrets, $fullKey)) {
                $this->unsetByDotPath($envSecrets, $fullKey);
                $envSecrets = $this->pruneEmptyArrays($envSecrets);
                $envDirty = true;
            }

            $this->logDebug('protected key deleted after removal from secret-split config', [
                'key' => $fullKey,
            ]);
        }

        if ($baseDirty) {
            $this->saveSecretsYamlFile($basePath, $baseSecrets);
        }
        if ($envDirty) {
            $this->saveSecretsYamlFile($envPath, $envSecrets);
        }
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,array{full_key:string,password:bool}>
     */
    private function getProtectedDefinitionsFromConfigArray(array $config): array
    {
        return $this->context->buildProtectedDefinitionsFromConfigValues(
            $config['protected_fields'] ?? [],
            $config['protected_keys'] ?? [],
            $config['password_keys'] ?? []
        );
    }

    private function hasByDotPath(array $data, string $path): bool
    {
        return $this->yaml->hasByDotPath($data, $path);
    }

    private function getByDotPath(array $data, string $path): mixed
    {
        return $this->yaml->getByDotPath($data, $path);
    }

    private function unsetByDotPath(array &$data, string $path): void
    {
        $this->yaml->unsetByDotPath($data, $path);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function pruneEmptyArrays(array $data): array
    {
        return $this->yaml->pruneEmptyArrays($data);
    }

    private function loadYamlFile(string $path): array
    {
        return $this->yaml->loadYamlFile($path);
    }

    private function saveYamlFile(string $path, array $data): void
    {
        $this->yaml->saveYamlFile($path, $data);
    }

    private function saveSecretsYamlFile(string $path, array $data): void
    {
        $this->yaml->saveSecretsYamlFile($path, $data);
    }

    private function logDebug(string $message, array $context = []): void
    {
        ($this->logDebug)($message, $context);
    }

    private function isCatalogPasswordField(string $fullKey): bool
    {
        return $this->context->isCatalogPasswordField($fullKey);
    }
}
