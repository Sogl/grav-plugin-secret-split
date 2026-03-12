<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Grav;

final class SecretSplitPathResolver
{
    private ?string $environmentName = null;

    private bool $missingEnvironmentLogged = false;

    public function __construct(
        private readonly Grav $grav,
        private readonly string $userDir
    ) {}

    public function getPluginConfigValue(string $key, mixed $default = null): mixed
    {
        $config = $this->grav['config'] ?? null;

        return $config ? $config->get('plugins.secret-split.' . $key, $default) : $default;
    }

    public function getEnvironmentName(): string
    {
        if ($this->environmentName !== null) {
            return $this->environmentName;
        }

        $setup = $this->grav['setup'] ?? null;
        $environment = null;

        if (is_object($setup) && property_exists($setup, 'environment')) {
            $environment = $setup->environment;
        }

        if (!is_string($environment) || trim($environment) === '') {
            $config = $this->grav['config'] ?? null;
            $environment = $config ? (string) $config->get('setup.environment', '') : '';
        }

        $environment = trim((string) $environment);
        if ($environment === '') {
            if (!$this->missingEnvironmentLogged) {
                $this->missingEnvironmentLogged = true;
                $log = $this->grav['log'] ?? null;
                if (is_object($log) && method_exists($log, 'warning')) {
                    $log->warning('[secret-split] environment name is not configured; env-specific secrets storage is disabled');
                }
            }

            $this->environmentName = '';

            return $this->environmentName;
        }

        $this->environmentName = $environment;

        return $this->environmentName;
    }

    public function getBaseStoragePath(): string
    {
        $configured = (string) $this->getPluginConfigValue('base_storage_file', 'user://secrets.yaml');

        return $this->resolveUserStoragePath($configured);
    }

    public function getEnvironmentStoragePath(): string
    {
        $environment = $this->getEnvironmentName();
        if ($environment === '') {
            return '';
        }

        $pattern = (string) $this->getPluginConfigValue('environment_storage_pattern', 'user://secrets.%s.yaml');

        return $this->resolveUserStoragePath(sprintf($pattern, $environment));
    }

    public function getTrackedPluginConfigPath(string $pluginSlug, string $scope): string
    {
        if ($scope === 'env') {
            $environment = $this->getEnvironmentName();
            if ($environment === '') {
                return '';
            }

            return $this->userDir . 'env/' . $environment . '/config/plugins/' . $pluginSlug . '.yaml';
        }

        return $this->userDir . 'config/plugins/' . $pluginSlug . '.yaml';
    }

    public function resolveUserStoragePath(string $path): string
    {
        if (str_starts_with($path, 'user://')) {
            return $this->userDir . substr($path, strlen('user://'));
        }

        return $path;
    }
}
