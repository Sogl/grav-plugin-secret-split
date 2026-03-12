<?php

declare(strict_types=1);

namespace Grav\Plugin;

final class SecretSplitStorageManager
{
    /** @var callable */
    private $getPluginBlueprintFieldOrder;

    public function __construct(
        private readonly SecretSplitPathResolver $paths,
        private readonly SecretSplitYamlHelper $yaml,
        callable $getPluginBlueprintFieldOrder
    ) {
        $this->getPluginBlueprintFieldOrder = $getPluginBlueprintFieldOrder;
    }

    public function extractPluginSlugFromFullKey(string $fullKey): ?string
    {
        if (!preg_match('~^plugins\.([^.]+)\.~', $fullKey, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public function extractRelativeKeyFromFullKey(string $fullKey, ?string $pluginSlug): ?string
    {
        if ($pluginSlug === null) {
            return null;
        }

        $prefix = 'plugins.' . $pluginSlug . '.';
        if (!str_starts_with($fullKey, $prefix)) {
            return null;
        }

        return substr($fullKey, strlen($prefix));
    }

    /**
     * @param array<string,mixed>|null $baseSecrets
     * @param array<string,mixed>|null $envSecrets
     * @return array{exists:bool,scope:string,value:mixed}
     */
    public function getSecretValueInfo(string $fullKey, ?array $baseSecrets = null, ?array $envSecrets = null): array
    {
        $baseSecrets = $baseSecrets ?? $this->loadYamlFile($this->getBaseStoragePath());
        $envSecrets = $envSecrets ?? $this->loadYamlFile($this->getEnvironmentStoragePath());

        if ($this->hasByDotPath($envSecrets, $fullKey)) {
            $value = $this->getByDotPath($envSecrets, $fullKey);
            if ($this->isMeaningfulProtectedValue($value)) {
                return ['exists' => true, 'scope' => 'env', 'value' => $value];
            }
        }

        if ($this->hasByDotPath($baseSecrets, $fullKey)) {
            $value = $this->getByDotPath($baseSecrets, $fullKey);
            if ($this->isMeaningfulProtectedValue($value)) {
                return ['exists' => true, 'scope' => 'base', 'value' => $value];
            }
        }

        return ['exists' => false, 'scope' => 'none', 'value' => null];
    }

    /**
     * @param array{base: array<string,mixed>, env: array<string,mixed>}|null $layers
     * @return array{exists:bool,scope:string,value:mixed}
     */
    public function getTrackedValueInfo(string $pluginSlug, string $relativeKey, ?array $layers = null): array
    {
        $layers = $layers ?? [
            'base' => $this->loadTrackedPluginConfig($pluginSlug, 'base'),
            'env' => $this->loadTrackedPluginConfig($pluginSlug, 'env'),
        ];

        if ($this->hasByDotPath($layers['env'], $relativeKey)) {
            $value = $this->getByDotPath($layers['env'], $relativeKey);
            if ($this->isMeaningfulProtectedValue($value)) {
                return ['exists' => true, 'scope' => 'env', 'value' => $value];
            }
        }

        if ($this->hasByDotPath($layers['base'], $relativeKey)) {
            $value = $this->getByDotPath($layers['base'], $relativeKey);
            if ($this->isMeaningfulProtectedValue($value)) {
                return ['exists' => true, 'scope' => 'base', 'value' => $value];
            }
        }

        return ['exists' => false, 'scope' => 'none', 'value' => null];
    }

    /**
     * @return array<string,mixed>
     */
    public function loadTrackedPluginConfig(string $pluginSlug, string $scope): array
    {
        return $this->loadYamlFile($this->getTrackedPluginConfigPath($pluginSlug, $scope));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveTrackedPluginConfig(string $pluginSlug, string $scope, array $data): void
    {
        $path = $this->getTrackedPluginConfigPath($pluginSlug, $scope);
        if ($path === '') {
            return;
        }

        $data = $this->yaml->pruneEmptyArrays($data);
        if ($data === []) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        $orderMap = $this->buildPluginConfigOrderMap($pluginSlug);
        if ($orderMap !== []) {
            $data = $this->reorderConfigByOrderMap($data, $orderMap);
        }

        $this->saveYamlFile($path, $data);
    }

    public function getTrackedPluginConfigPath(string $pluginSlug, string $scope): string
    {
        return $this->paths->getTrackedPluginConfigPath($pluginSlug, $scope);
    }

    public function getBaseStoragePath(): string
    {
        return $this->paths->getBaseStoragePath();
    }

    public function getEnvironmentStoragePath(): string
    {
        return $this->paths->getEnvironmentStoragePath();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPluginConfigOrderMap(string $pluginSlug): array
    {
        if ($pluginSlug === '') {
            return [];
        }

        $template = $this->loadDefaultPluginConfigTemplate($pluginSlug);
        $templatePaths = $this->extractOrderedConfigPaths($template);
        $blueprintPaths = ($this->getPluginBlueprintFieldOrder)($pluginSlug);
        $mergedPaths = $this->mergeOrderedPaths($templatePaths, $blueprintPaths);

        return $this->buildOrderMapFromPaths($mergedPaths);
    }

    /**
     * @return array<string,mixed>
     */
    private function loadDefaultPluginConfigTemplate(string $pluginSlug): array
    {
        return $this->loadYamlFile(USER_DIR . 'plugins/' . $pluginSlug . '/' . $pluginSlug . '.yaml');
    }

    private function isMeaningfulProtectedValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return !(is_string($value) && trim($value) === '');
    }

    /**
     * @return array<string,mixed>
     */
    private function loadYamlFile(string $path): array
    {
        return $this->yaml->loadYamlFile($path);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function saveSecretsYamlFile(string $path, array $data): void
    {
        $data = $this->yaml->pruneEmptyArrays($data);
        $this->yaml->saveSecretsYamlFile($path, $data);
    }

    /**
     * @return array<string,mixed>
     */
    public function loadYamlLayer(string $path): array
    {
        if ($path === '') {
            return [];
        }

        return $this->loadYamlFile($path);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function saveYamlFile(string $path, array $data): void
    {
        $this->yaml->saveYamlFile($path, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function setByDotPath(array &$data, string $path, mixed $value): void
    {
        $this->yaml->setByDotPath($data, $path, $value);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function unsetByDotPath(array &$data, string $path): void
    {
        $this->yaml->unsetByDotPath($data, $path);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function hasByDotPath(array $data, string $path): bool
    {
        return $this->yaml->hasByDotPath($data, $path);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function getByDotPath(array $data, string $path): mixed
    {
        return $this->yaml->getByDotPath($data, $path);
    }

    /**
     * @param array<string,mixed> $data
     * @return string[]
     */
    private function extractOrderedConfigPaths(array $data): array
    {
        return $this->yaml->extractOrderedConfigPaths($data);
    }

    /**
     * @param string[] $primary
     * @param string[] $secondary
     * @return string[]
     */
    private function mergeOrderedPaths(array $primary, array $secondary): array
    {
        return $this->yaml->mergeOrderedPaths($primary, $secondary);
    }

    /**
     * @param string[] $paths
     * @return array<string,mixed>
     */
    private function buildOrderMapFromPaths(array $paths): array
    {
        return $this->yaml->buildOrderMapFromPaths($paths);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $orderMap
     * @return array<string,mixed>
     */
    private function reorderConfigByOrderMap(array $data, array $orderMap): array
    {
        return $this->yaml->reorderConfigByOrderMap($data, $orderMap);
    }
}
