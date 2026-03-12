<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Data;

final class SecretSplitContext
{
    /** @var callable */
    private $getProtectedFieldCatalog;

    public function __construct(
        private readonly SecretSplitPathResolver $paths,
        callable $getProtectedFieldCatalog
    ) {
        $this->getProtectedFieldCatalog = $getProtectedFieldCatalog;
    }

    public function getPluginConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->paths->getPluginConfigValue($key, $default);
    }

    public function getEnvironmentName(): string
    {
        return $this->paths->getEnvironmentName();
    }

    public function getBaseStoragePath(): string
    {
        return $this->paths->getBaseStoragePath();
    }

    public function getEnvironmentStoragePath(): string
    {
        return $this->paths->getEnvironmentStoragePath();
    }

    public function resolveUserStoragePath(string $path): string
    {
        return $this->paths->resolveUserStoragePath($path);
    }

    /**
     * @return array<int,array{full_key:string,password:bool}>
     */
    public function getProtectedDefinitions(): array
    {
        return $this->buildProtectedDefinitionsFromConfigValues(
            $this->getPluginConfigValue('protected_fields', []),
            $this->getPluginConfigValue('protected_keys', []),
            $this->getPluginConfigValue('password_keys', [])
        );
    }

    public function isPasswordKey(string $fullKey): bool
    {
        foreach ($this->getProtectedDefinitions() as $definition) {
            if ($definition['full_key'] === $fullKey && $definition['password']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $entries
     * @param mixed $protectedKeys
     * @param mixed $passwordKeys
     * @return array<int,array{full_key:string,password:bool}>
     */
    public function buildProtectedDefinitionsFromConfigValues(mixed $entries, mixed $protectedKeys = [], mixed $passwordKeys = []): array
    {
        $definitions = [];
        $entries = is_array($entries) ? $entries : [];
        $hasStructuredConfig = $entries !== [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $nestedFields = $entry['fields'] ?? null;
            if (is_array($nestedFields)) {
                foreach ($nestedFields as $fieldEntry) {
                    if (!is_array($fieldEntry)) {
                        continue;
                    }

                    $fullKey = trim((string) ($fieldEntry['field_key'] ?? ''));
                    if ($fullKey === '' || !str_starts_with($fullKey, 'plugins.') || str_starts_with($fullKey, 'plugins.secret-split.')) {
                        continue;
                    }

                    $definitions[] = [
                        'full_key' => $fullKey,
                        'password' => array_key_exists('password', $fieldEntry)
                            ? (bool) $fieldEntry['password']
                            : $this->isCatalogPasswordField($fullKey),
                    ];
                }

                continue;
            }

            $fullKey = trim((string) ($entry['field_key'] ?? ''));
            if ($fullKey === '' || !str_starts_with($fullKey, 'plugins.') || str_starts_with($fullKey, 'plugins.secret-split.')) {
                continue;
            }

            $definitions[] = [
                'full_key' => $fullKey,
                'password' => array_key_exists('password', $entry)
                    ? (bool) $entry['password']
                    : $this->isCatalogPasswordField($fullKey),
            ];
        }

        if ($definitions !== [] || $hasStructuredConfig) {
            return $definitions;
        }

        $passwordKeys = array_map('strval', (array) $passwordKeys);
        foreach ((array) $protectedKeys as $fullKey) {
            $fullKey = trim((string) $fullKey);
            if ($fullKey === '' || !str_starts_with($fullKey, 'plugins.') || str_starts_with($fullKey, 'plugins.secret-split.')) {
                continue;
            }

            $definitions[] = [
                'full_key' => $fullKey,
                'password' => in_array($fullKey, $passwordKeys, true) || $this->isCatalogPasswordField($fullKey),
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,array{full_key:string,password:bool}>
     */
    public function getProtectedDefinitionsFromConfigArray(array $config): array
    {
        return $this->buildProtectedDefinitionsFromConfigValues(
            $config['protected_fields'] ?? [],
            $config['protected_keys'] ?? [],
            $config['password_keys'] ?? []
        );
    }

    /**
     * @return array<int,array{full_key:string,password:bool}>
     */
    public function getProtectedDefinitionsFromSavedObject(Data $object): array
    {
        $config = method_exists($object, 'toArray') ? $object->toArray() : null;
        if (!is_array($config)) {
            $config = [
                'protected_fields' => $object->get('protected_fields', []),
                'protected_keys' => $object->get('protected_keys', []),
                'password_keys' => $object->get('password_keys', []),
            ];
        }

        return $this->getProtectedDefinitionsFromConfigArray($config);
    }

    public function isCatalogPasswordField(string $fullKey): bool
    {
        $catalog = ($this->getProtectedFieldCatalog)();

        return in_array($fullKey, $catalog['passwordFields'] ?? [], true);
    }
}
