<?php

declare(strict_types=1);

namespace Grav\Plugin;

final class SecretSplitStateManager
{
    public function __construct(
        private readonly SecretSplitStorageManager $storage
    ) {}

    /**
     * @param array<string,mixed> $catalogFields
     * @param list<array{full_key:string,password:bool}> $definitions
     * @return array{
     *   fields: array<string, array{status:string,label:string,source:string}>,
     *   facts: array<string, array{
     *     status:string,
     *     label:string,
     *     source:string,
     *     secret_exists:bool,
     *     tracked_exists:bool,
     *     secret_scope:string,
     *     tracked_scope:string
     *   }>,
     *   counts: array<string,int>,
     *   actions: array{migrate:string,return:string},
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
        string $baseSecretsPath,
        string $envSecretsPath,
        string $migrateUrl,
        string $returnUrl,
        array $sourceLabels,
        callable $translate,
        callable $buildSourceLabel,
        callable $buildDuplicateSourceLabel
    ): array {
        $baseSecrets = $this->loadSecretsLayer($baseSecretsPath);
        $envSecrets = $this->loadSecretsLayer($envSecretsPath);
        $counts = [
            'stored' => 0,
            'pending' => 0,
            'duplicate' => 0,
            'missing' => 0,
        ];
        $fields = [];
        $facts = [];

        foreach (array_keys($catalogFields) as $fullKey) {
            $pluginSlug = $this->storage->extractPluginSlugFromFullKey($fullKey);
            $relativeKey = $this->storage->extractRelativeKeyFromFullKey($fullKey, $pluginSlug);
            if ($pluginSlug === null || $relativeKey === null) {
                continue;
            }

            $secretInfo = $this->storage->getSecretValueInfo($fullKey, $baseSecrets, $envSecrets);
            $trackedInfo = $this->storage->getTrackedValueInfo($pluginSlug, $relativeKey);
            $facts[$fullKey] = $this->buildFieldState(
                $pluginSlug,
                $secretInfo,
                $trackedInfo,
                $translate,
                $buildSourceLabel,
                $buildDuplicateSourceLabel
            );
        }

        foreach ($definitions as $definition) {
            $fullKey = $definition['full_key'];
            if (!isset($facts[$fullKey])) {
                continue;
            }

            $state = $facts[$fullKey];
            $counts[$state['status']]++;
            $fields[$fullKey] = [
                'status' => $state['status'],
                'label' => $state['label'],
                'source' => $state['source'],
            ];
        }

        return [
            'fields' => $fields,
            'facts' => $facts,
            'counts' => $counts,
            'actions' => [
                'migrate' => $migrateUrl,
                'return' => $returnUrl,
            ],
            'meta' => [
                'env_storage_available' => $envSecretsPath !== '' && is_file($envSecretsPath),
                'base_storage_file' => basename($baseSecretsPath),
                'env_storage_file' => $envSecretsPath !== '' ? basename($envSecretsPath) : '',
                'source_labels' => $sourceLabels,
            ],
        ];
    }

    /**
     * @param list<array{full_key:string,password:bool}> $definitions
     * @return array{migrated:int,normalized:int,missing:int}
     */
    public function migrateProtectedValues(
        array $definitions,
        string $baseSecretsPath,
        string $envSecretsPath,
        callable $resolveStorageTarget,
        callable $logDebug
    ): array {
        $hasEnvStorage = $envSecretsPath !== '' && is_file($envSecretsPath);
        $baseSecrets = $this->loadSecretsLayer($baseSecretsPath);
        $envSecrets = $this->loadSecretsLayer($envSecretsPath);
        $trackedLayers = [];
        $dirtyTracked = [];
        $baseDirty = false;
        $envDirty = false;
        $summary = ['migrated' => 0, 'normalized' => 0, 'missing' => 0];

        foreach ($definitions as $definition) {
            $fullKey = $definition['full_key'];
            $pluginSlug = $this->storage->extractPluginSlugFromFullKey($fullKey);
            $relativeKey = $this->storage->extractRelativeKeyFromFullKey($fullKey, $pluginSlug);
            if ($pluginSlug === null || $relativeKey === null) {
                continue;
            }

            if (!isset($trackedLayers[$pluginSlug])) {
                $trackedLayers[$pluginSlug] = [
                    'base' => $this->storage->loadTrackedPluginConfig($pluginSlug, 'base'),
                    'env' => $this->storage->loadTrackedPluginConfig($pluginSlug, 'env'),
                ];
                $dirtyTracked[$pluginSlug] = ['base' => false, 'env' => false];
            }

            $secretInfo = $this->storage->getSecretValueInfo($fullKey, $baseSecrets, $envSecrets);
            $trackedInfo = $this->storage->getTrackedValueInfo($pluginSlug, $relativeKey, $trackedLayers[$pluginSlug]);

            if (!$trackedInfo['exists']) {
                if (!$secretInfo['exists']) {
                    $summary['missing']++;
                }
                continue;
            }

            $trackedValue = $trackedInfo['value'];
            if ($trackedValue === null || (is_string($trackedValue) && trim($trackedValue) === '')) {
                $logDebug('tracked protected value skipped during migrate due to empty payload', [
                    'plugin' => $pluginSlug,
                    'key' => $fullKey,
                    'scope' => $trackedInfo['scope'],
                ]);
                continue;
            }

            $target = $resolveStorageTarget($fullKey, $baseSecrets, $envSecrets, $hasEnvStorage);
            if ($target === 'base') {
                $this->storage->setByDotPath($baseSecrets, $fullKey, $trackedValue);
                $baseDirty = true;
            } else {
                $this->storage->setByDotPath($envSecrets, $fullKey, $trackedValue);
                $envDirty = true;
            }

            $scope = $trackedInfo['scope'] === 'env' ? 'env' : 'base';
            $this->storage->unsetByDotPath($trackedLayers[$pluginSlug][$scope], $relativeKey);
            $dirtyTracked[$pluginSlug][$scope] = true;

            if ($secretInfo['exists']) {
                $summary['normalized']++;
            } else {
                $summary['migrated']++;
            }
        }

        if ($baseDirty) {
            $this->storage->saveSecretsYamlFile($baseSecretsPath, $baseSecrets);
        }
        if ($envDirty) {
            $this->storage->saveSecretsYamlFile($envSecretsPath, $envSecrets);
        }

        foreach ($trackedLayers as $pluginSlug => $layers) {
            foreach (['base', 'env'] as $scope) {
                if (!$dirtyTracked[$pluginSlug][$scope]) {
                    continue;
                }
                $this->storage->saveTrackedPluginConfig($pluginSlug, $scope, $layers[$scope]);
            }
        }

        return $summary;
    }

    /**
     * @param list<array{full_key:string,password:bool}> $definitions
     * @return array{returned:int,missing:int}
     */
    public function returnProtectedValuesToTrackedConfig(
        array $definitions,
        string $baseSecretsPath,
        string $envSecretsPath
    ): array {
        $baseSecrets = $this->loadSecretsLayer($baseSecretsPath);
        $envSecrets = $this->loadSecretsLayer($envSecretsPath);
        $trackedLayers = [];
        $dirtyTracked = [];
        $baseDirty = false;
        $envDirty = false;
        $summary = ['returned' => 0, 'missing' => 0];

        foreach ($definitions as $definition) {
            $fullKey = $definition['full_key'];
            $pluginSlug = $this->storage->extractPluginSlugFromFullKey($fullKey);
            $relativeKey = $this->storage->extractRelativeKeyFromFullKey($fullKey, $pluginSlug);
            if ($pluginSlug === null || $relativeKey === null) {
                continue;
            }

            if (!isset($trackedLayers[$pluginSlug])) {
                $trackedLayers[$pluginSlug] = [
                    'base' => $this->storage->loadTrackedPluginConfig($pluginSlug, 'base'),
                    'env' => $this->storage->loadTrackedPluginConfig($pluginSlug, 'env'),
                ];
                $dirtyTracked[$pluginSlug] = ['base' => false, 'env' => false];
            }

            $secretInfo = $this->storage->getSecretValueInfo($fullKey, $baseSecrets, $envSecrets);
            if (!$secretInfo['exists']) {
                $summary['missing']++;
                continue;
            }

            $scope = $secretInfo['scope'] === 'env' ? 'env' : 'base';
            $this->storage->setByDotPath($trackedLayers[$pluginSlug][$scope], $relativeKey, $secretInfo['value']);
            $dirtyTracked[$pluginSlug][$scope] = true;

            if ($secretInfo['scope'] === 'env') {
                $this->storage->unsetByDotPath($envSecrets, $fullKey);
                $envDirty = true;
            } else {
                $this->storage->unsetByDotPath($baseSecrets, $fullKey);
                $baseDirty = true;
            }

            $summary['returned']++;
        }

        if ($baseDirty) {
            $this->storage->saveSecretsYamlFile($baseSecretsPath, $baseSecrets);
        }
        if ($envDirty) {
            $this->storage->saveSecretsYamlFile($envSecretsPath, $envSecrets);
        }

        foreach ($trackedLayers as $pluginSlug => $layers) {
            foreach (['base', 'env'] as $scope) {
                if (!$dirtyTracked[$pluginSlug][$scope]) {
                    continue;
                }
                $this->storage->saveTrackedPluginConfig($pluginSlug, $scope, $layers[$scope]);
            }
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $baseSecrets
     * @param array<string,mixed> $envSecrets
     * @return array{
     *   status:string,
     *   label:string,
     *   source:string,
     *   secret_exists:bool,
     *   tracked_exists:bool,
     *   secret_scope:string,
     *   tracked_scope:string
     * }
     */
    private function buildFieldState(
        string $pluginSlug,
        array $secretInfo,
        array $trackedInfo,
        callable $translate,
        callable $buildSourceLabel,
        callable $buildDuplicateSourceLabel
    ): array {
        if ($secretInfo['exists'] && $trackedInfo['exists']) {
            $status = 'duplicate';
            $label = $translate('PLUGIN_SECRET_SPLIT.STATUS.DUPLICATE');
            $source = $buildDuplicateSourceLabel($pluginSlug, $trackedInfo['scope'], $secretInfo['scope']);
        } elseif ($secretInfo['exists']) {
            $status = 'stored';
            $label = $translate('PLUGIN_SECRET_SPLIT.STATUS.STORED');
            $source = $buildSourceLabel($pluginSlug, $secretInfo['scope'], 'secrets');
        } elseif ($trackedInfo['exists']) {
            $status = 'pending';
            $label = $translate('PLUGIN_SECRET_SPLIT.STATUS.PENDING');
            $source = $buildSourceLabel($pluginSlug, $trackedInfo['scope'], 'tracked');
        } else {
            $status = 'missing';
            $label = $translate('PLUGIN_SECRET_SPLIT.STATUS.MISSING');
            $source = $translate('PLUGIN_SECRET_SPLIT.SOURCE.NOT_SET');
        }

        return [
            'status' => $status,
            'label' => $label,
            'source' => $source,
            'secret_exists' => $secretInfo['exists'],
            'tracked_exists' => $trackedInfo['exists'],
            'secret_scope' => $secretInfo['scope'],
            'tracked_scope' => $trackedInfo['scope'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSecretsLayer(string $path): array
    {
        return $this->storage->loadYamlLayer($path);
    }
}
