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

    /**
     * Overlays only currently protected leaf values, atomically — protected
     * fields are the authority: stale or manually added keys in the secrets
     * files are never promoted into live config, and an array-valued secret
     * replaces the tracked value wholesale instead of being index-merged.
     *
     * @param array<int,array{full_key:string,password:bool}> $definitions
     */
    public function applySecretOverlay(object $config, string $baseSecretsPath, string $envSecretsPath, array $definitions): void
    {
        $baseSecrets = $this->loadYamlFile($baseSecretsPath);
        $envSecrets = $this->loadYamlFile($envSecretsPath);

        foreach ($definitions as $definition) {
            $fullKey = $definition['full_key'];
            if ($this->hasByDotPath($envSecrets, $fullKey)) {
                $config->set($fullKey, $this->getByDotPath($envSecrets, $fullKey));
                continue;
            }

            if ($this->hasByDotPath($baseSecrets, $fullKey)) {
                $config->set($fullKey, $this->getByDotPath($baseSecrets, $fullKey));
            }
        }
    }

    /**
     * @param array<int,array{full_key:string,password:bool}> $definitions
     * @param array<string,mixed> $submittedData
     * @param callable(string):bool $isPasswordKey
     * @param callable(string,array<string,mixed>,array<string,mixed>,bool,string):string $resolveStorageTarget
     * @param callable(string,array<string,mixed>):void $logDebug
     * @param array<string,mixed>|null $previousTracked the target YAML file's
     *        pre-save disk contents (api saves only — lets us tell a real edit
     *        apart from a round-tripped value the form never actually showed)
     * @param callable(string):array{has:bool,value:mixed}|null $getFieldDefault
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
        callable $logDebug,
        string $preferredScope = '',
        bool $isApiRequest = false,
        ?array $previousTracked = null,
        ?callable $getFieldDefault = null
    ): void {
        $protectedKeys = $this->getProtectedKeysForPlugin($pluginSlug, $definitions);
        if ($protectedKeys === []) {
            return;
        }

        $this->yaml->withStorageLock($basePath, function () use (
            $pluginSlug,
            &$source,
            $submittedData,
            $basePath,
            $envPath,
            $protectedKeys,
            $isPasswordKey,
            $resolveStorageTarget,
            $logDebug,
            $preferredScope,
            $isApiRequest,
            $previousTracked,
            $getFieldDefault
        ): void {
            $this->doExtractProtectedValuesForPlugin(
                $pluginSlug,
                $source,
                $submittedData,
                $basePath,
                $envPath,
                $protectedKeys,
                $isPasswordKey,
                $resolveStorageTarget,
                $logDebug,
                $preferredScope,
                $isApiRequest,
                $previousTracked,
                $getFieldDefault
            );
        });
    }

    /**
     * @param array<int,string> $protectedKeys
     * @param array<string,mixed> $submittedData
     * @param callable(string):bool $isPasswordKey
     * @param callable(string,array<string,mixed>,array<string,mixed>,bool,string):string $resolveStorageTarget
     * @param callable(string,array<string,mixed>):void $logDebug
     * @param array<string,mixed>|null $previousTracked
     * @param callable(string):array{has:bool,value:mixed}|null $getFieldDefault
     */
    private function doExtractProtectedValuesForPlugin(
        string $pluginSlug,
        Data|array &$source,
        array $submittedData,
        string $basePath,
        string $envPath,
        array $protectedKeys,
        callable $isPasswordKey,
        callable $resolveStorageTarget,
        callable $logDebug,
        string $preferredScope,
        bool $isApiRequest,
        ?array $previousTracked,
        ?callable $getFieldDefault
    ): void {
        $hasEnvStorage = $envPath !== '' && is_file($envPath);
        $baseSecrets = $this->loadYamlFile($basePath);
        $envSecrets = $this->loadYamlFile($envPath);
        $baseDirty = false;
        $envDirty = false;

        foreach ($protectedKeys as $relativeKey) {
            $fullKey = 'plugins.' . $pluginSlug . '.' . $relativeKey;
            $submittedEmpty = $this->wasSubmittedValueCleared($submittedData, $relativeKey);

            // Admin Next's config form is rendered from on-disk YAML, not the
            // overlaid runtime config — a stored secret is invisible to it, so
            // an empty, unchanged, or blueprint-default round-trip is NOT user
            // intent and must not delete or overwrite the stored value.
            if ($isApiRequest
                && ($this->hasByDotPath($baseSecrets, $fullKey) || $this->hasByDotPath($envSecrets, $fullKey))
                && $this->isApiFormEcho($relativeKey, $fullKey, $source, $submittedData, $previousTracked, $getFieldDefault)
            ) {
                $this->removeValue($source, $relativeKey);
                $logDebug('protected key preserved on api save (unchanged echo)', [
                    'plugin' => $pluginSlug,
                    'key' => $fullKey,
                ]);
                continue;
            }

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

            $target = $resolveStorageTarget($fullKey, $baseSecrets, $envSecrets, $hasEnvStorage, $preferredScope);
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

    /**
     * True when the submitted value merely echoes what Admin Next rendered —
     * i.e. the admin could not have edited the secret because the form never
     * contained it: no submitted key, an empty value, the unchanged previous
     * tracked value, or the blueprint default.
     *
     * @param array<string,mixed>|null $previousTracked
     * @param callable(string):array{has:bool,value:mixed}|null $getFieldDefault
     */
    private function isApiFormEcho(
        string $relativeKey,
        string $fullKey,
        Data|array $source,
        array $submittedData,
        ?array $previousTracked,
        ?callable $getFieldDefault
    ): bool {
        if (!$this->hasByDotPath($submittedData, $relativeKey)) {
            return true;
        }

        $value = $this->readValue($source, $relativeKey);
        if ($value === '' || $value === null) {
            return true;
        }

        $hadTracked = is_array($previousTracked) && $this->hasByDotPath($previousTracked, $relativeKey);
        if ($hadTracked) {
            return $value === $this->getByDotPath($previousTracked, $relativeKey);
        }

        if ($getFieldDefault !== null) {
            $default = $getFieldDefault($fullKey);

            return $default['has'] && $value === $default['value'];
        }

        return false;
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
