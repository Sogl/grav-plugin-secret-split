<?php

declare(strict_types=1);

namespace Grav\Plugin;

final class SecretSplitApplicationService
{
    public function __construct(
        private readonly SecretSplitPathResolver $paths,
        private readonly SecretSplitStorageManager $storage,
        private readonly SecretSplitStateManager $state,
        private readonly SecretSplitMutationService $mutation,
        private readonly SecretSplitI18n $i18n
    ) {}

    /**
     * @param array<string,mixed> $catalogFields
     * @param list<array{full_key:string,password:bool}> $definitions
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
     *   meta: array{
     *     env_storage_available:bool,
     *     base_storage_file:string,
     *     env_storage_file:string,
     *     source_labels: array{
     *       base_secrets:string,
     *       env_secrets:string,
     *       base_config:string,
     *       env_config:string,
     *       not_set:string
     *     }
     *   }
     * }
     */
    public function buildProtectedFieldStateCatalog(
        array $catalogFields,
        array $definitions,
        string $migrateUrl,
        string $returnUrl,
        callable $translate
    ): array {
        return $this->state->buildProtectedFieldStateCatalog(
            $catalogFields,
            $definitions,
            $this->paths->getBaseStoragePath(),
            $this->paths->getEnvironmentStoragePath(),
            $migrateUrl,
            $returnUrl,
            $this->i18n->getSourceLabels(),
            $translate,
            [$this->i18n, 'buildSourceLabel'],
            [$this->i18n, 'buildDuplicateSourceLabel']
        );
    }

    /**
     * @param list<array{full_key:string,password:bool}> $definitions
     * @return array{migrated:int,normalized:int,missing:int}
     */
    public function migrateProtectedValues(array $definitions, callable $resolveStorageTarget, callable $logDebug): array
    {
        return $this->state->migrateProtectedValues(
            $definitions,
            $this->paths->getBaseStoragePath(),
            $this->paths->getEnvironmentStoragePath(),
            $resolveStorageTarget,
            $logDebug
        );
    }

    /**
     * @param list<array{full_key:string,password:bool}> $definitions
     * @return array{returned:int,missing:int}
     */
    public function returnProtectedValuesToTrackedConfig(array $definitions): array
    {
        return $this->state->returnProtectedValuesToTrackedConfig(
            $definitions,
            $this->paths->getBaseStoragePath(),
            $this->paths->getEnvironmentStoragePath()
        );
    }

    /**
     * @return array{base:?string,env:?string}
     */
    public function snapshotTrackedConfig(string $pluginSlug): array
    {
        return [
            'base' => $this->readFileSnapshot($this->paths->getTrackedPluginConfigPath($pluginSlug, 'base')),
            'env' => $this->readFileSnapshot($this->paths->getTrackedPluginConfigPath($pluginSlug, 'env')),
        ];
    }

    /**
     * @param array{base:?string,env:?string} $snapshots
     * @return string[]
     */
    public function detectChangedTrackedScopes(string $pluginSlug, array $snapshots): array
    {
        $changedScopes = [];

        foreach (['base', 'env'] as $scope) {
            $path = $this->paths->getTrackedPluginConfigPath($pluginSlug, $scope);
            if ($this->readFileSnapshot($path) !== ($snapshots[$scope] ?? null)) {
                $changedScopes[] = $scope;
            }
        }

        return $changedScopes;
    }

    /**
     * @param list<array{full_key:string,password:bool}> $definitions
     * @param string[] $scopes
     * @return array{migrated:int}
     */
    public function processFlexTrackedConfigMigration(
        string $pluginSlug,
        array $definitions,
        array $scopes,
        callable $isPasswordKey,
        callable $resolveStorageTarget,
        callable $logDebug
    ): array {
        $summary = ['migrated' => 0];
        $definitions = array_values(array_filter(
            $definitions,
            static fn(array $definition): bool => str_starts_with($definition['full_key'], 'plugins.' . $pluginSlug . '.')
        ));

        if ($definitions === []) {
            return $summary;
        }

        foreach ($scopes as $scope) {
            $path = $this->paths->getTrackedPluginConfigPath($pluginSlug, $scope);
            if ($path === '' || !is_file($path)) {
                continue;
            }

            $trackedData = $this->storage->loadTrackedPluginConfig($pluginSlug, $scope);
            $before = $trackedData;
            $this->mutation->extractProtectedValuesForPlugin(
                $pluginSlug,
                $trackedData,
                $definitions,
                $before,
                $this->paths->getBaseStoragePath(),
                $this->paths->getEnvironmentStoragePath(),
                $isPasswordKey,
                $resolveStorageTarget,
                $logDebug,
                $scope
            );

            if ($trackedData !== $before) {
                $this->storage->saveTrackedPluginConfig($pluginSlug, $scope, $trackedData);
                $summary['migrated']++;
            }
        }

        return $summary;
    }

    private function readFileSnapshot(string $path): ?string
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }
}
