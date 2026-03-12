<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Data\Data;

final class SecretSplitMutationService
{
    public function __construct(
        private readonly SecretSplitYamlHelper $yaml
    ) {
    }

    /**
     * @param array<int,array{full_key:string,password:bool}> $definitions
     */
    public function getProtectedKeysForPlugin(string $pluginSlug, array $definitions): array
    {
        $keys = [];
        $prefix = 'plugins.' . $pluginSlug . '.';

        foreach ($definitions as $definition) {
            $fullKey = $definition['full_key'];
            if (!str_starts_with($fullKey, $prefix)) {
                continue;
            }

            $keys[] = substr($fullKey, strlen($prefix));
        }

        return array_values(array_unique($keys));
    }

    public function applySecretOverlay(object $config, string $baseSecretsPath, string $envSecretsPath): void
    {
        $baseSecrets = $this->loadYamlFile($baseSecretsPath);
        $envSecrets = $this->loadYamlFile($envSecretsPath);

        foreach ([$baseSecrets, $envSecrets] as $overrides) {
            foreach ($overrides as $key => $value) {
                if (!is_array($value)) {
                    $config->set($key, $value);
                    continue;
                }

                $current = $config->get($key);
                $current = is_array($current) ? $current : [];
                $config->set($key, array_replace_recursive($current, $value));
            }
        }
    }

    /**
     * @param array<int,array{full_key:string,password:bool}> $definitions
     * @param array<string,mixed> $submittedData
     * @param callable(string):bool $isPasswordKey
     * @param callable(string,array<string,mixed>,array<string,mixed>,bool):string $resolveStorageTarget
     * @param callable(string,array<string,mixed>):void $logDebug
     */
    public function extractProtectedValuesForPlugin(
        string $pluginSlug,
        Data|array &$source,
        array $definitions,
        array $submittedData,
        string $basePath,
        string $envPath,
        callable $isPasswordKey,
        callable $resolveStorageTarget,
        callable $logDebug
    ): void {
        $protectedKeys = $this->getProtectedKeysForPlugin($pluginSlug, $definitions);
        if ($protectedKeys === []) {
            return;
        }

        $hasEnvStorage = $envPath !== '' && is_file($envPath);
        $baseSecrets = $this->loadYamlFile($basePath);
        $envSecrets = $this->loadYamlFile($envPath);
        $baseDirty = false;
        $envDirty = false;

        foreach ($protectedKeys as $relativeKey) {
            $fullKey = 'plugins.' . $pluginSlug . '.' . $relativeKey;
            $submittedEmpty = $this->wasSubmittedValueCleared($submittedData, $relativeKey);

            if ($submittedEmpty && !$isPasswordKey($fullKey)) {
                $this->deleteProtectedValue($fullKey, $baseSecrets, $envSecrets, $baseDirty, $envDirty);
                $this->removeValue($source, $relativeKey);
                $logDebug('protected key deleted after empty submit', [
                    'plugin' => $pluginSlug,
                    'key' => $fullKey,
                ]);
                continue;
            }

            $value = $this->readValue($source, $relativeKey);
            $logDebug('inspect protected key', [
                'plugin' => $pluginSlug,
                'key' => $fullKey,
                'value_type' => gettype($value),
                'is_null' => $value === null,
                'is_empty_string' => $value === '',
            ]);

            if ($isPasswordKey($fullKey) && $value === '') {
                $this->removeValue($source, $relativeKey);
                $logDebug('password key skipped on empty value', [
                    'plugin' => $pluginSlug,
                    'key' => $fullKey,
                ]);
                continue;
            }

            if ($value === null) {
                $this->removeValue($source, $relativeKey);
                continue;
            }

            $target = $resolveStorageTarget($fullKey, $baseSecrets, $envSecrets, $hasEnvStorage);
            if ($target === 'base') {
                $this->setByDotPath($baseSecrets, $fullKey, $value);
                $baseDirty = true;
            } else {
                $this->setByDotPath($envSecrets, $fullKey, $value);
                $envDirty = true;
            }

            $this->removeValue($source, $relativeKey);
            $logDebug('protected key extracted', [
                'plugin' => $pluginSlug,
                'key' => $fullKey,
                'target' => $target,
            ]);
        }

        if ($baseDirty) {
            $this->saveSecretsYamlFile($basePath, $baseSecrets);
            $logDebug('base secrets file updated', [
                'path' => $basePath,
                'plugin' => $pluginSlug,
            ]);
        }

        if ($envDirty) {
            $this->saveSecretsYamlFile($envPath, $envSecrets);
            $logDebug('env secrets file updated', [
                'path' => $envPath,
                'plugin' => $pluginSlug,
            ]);
        }
    }

    private function deleteProtectedValue(string $fullKey, array &$baseSecrets, array &$envSecrets, bool &$baseDirty, bool &$envDirty): void
    {
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
    }

    private function wasSubmittedValueCleared(array $submittedData, string $relativeKey): bool
    {
        if (!$this->hasByDotPath($submittedData, $relativeKey)) {
            return false;
        }

        return $this->getByDotPath($submittedData, $relativeKey) === '';
    }

    private function readValue(Data|array $source, string $path): mixed
    {
        if ($source instanceof Data) {
            return $source->get($path);
        }

        return $this->getByDotPath($source, $path);
    }

    private function removeValue(Data|array &$source, string $path): void
    {
        if ($source instanceof Data) {
            $source->undef($path);
            return;
        }

        $this->unsetByDotPath($source, $path);
    }

    private function loadYamlFile(string $path): array
    {
        return $this->yaml->loadYamlFile($path);
    }

    private function saveSecretsYamlFile(string $path, array $data): void
    {
        $this->yaml->saveSecretsYamlFile($path, $data);
    }

    private function setByDotPath(array &$data, string $path, mixed $value): void
    {
        $this->yaml->setByDotPath($data, $path, $value);
    }

    private function unsetByDotPath(array &$data, string $path): void
    {
        $this->yaml->unsetByDotPath($data, $path);
    }

    private function hasByDotPath(array $data, string $path): bool
    {
        return $this->yaml->hasByDotPath($data, $path);
    }

    private function getByDotPath(array $data, string $path): mixed
    {
        return $this->yaml->getByDotPath($data, $path);
    }

    private function pruneEmptyArrays(array $data): array
    {
        return $this->yaml->pruneEmptyArrays($data);
    }
}
