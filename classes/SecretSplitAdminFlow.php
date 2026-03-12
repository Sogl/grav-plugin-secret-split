<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Grav;

final class SecretSplitAdminFlow
{
    /** @var callable */
    private $logDebug;

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
            if (is_array($body)) {
                return $body;
            }
        }

        return is_array($_POST) ? $_POST : [];
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
        $data = $post['data'] ?? [];

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
        $currentConfig = $this->getSubmittedPluginDataFromRequest();
        $configPath = $this->getSecretSplitConfigPath();
        $previousConfig = $this->loadYamlFile($configPath);
        $currentConfig = $this->preserveLegacyPasswordFlags($currentConfig, $previousConfig);

        $this->deleteSecretsForRemovedDefinitions(
            $this->getProtectedDefinitionsFromConfigArray($previousConfig),
            $this->getProtectedDefinitionsFromConfigArray($currentConfig),
            $getBaseStoragePath(),
            $getEnvironmentStoragePath()
        );

        $this->saveYamlFile($configPath, $currentConfig);
        $updateRuntimeConfig($currentConfig);
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
