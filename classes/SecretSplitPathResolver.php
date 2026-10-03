<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Grav;
use Symfony\Component\Yaml\Yaml;

final class SecretSplitPathResolver
{
    private ?string $environmentName = null;

    /**
     * Admin Next sends the operator's environment selection per request
     * (X-Config-Environment / X-Grav-Environment headers); the container is
     * already set up by then, so the booted environment cannot be switched.
     * Null keeps the booted environment — Grav 1.7 admin, front-end and CLI
     * requests send no header and are unaffected. '' is the explicit
     * base-only view ("Default" in the admin-next switcher).
     */
    private ?string $environmentOverride = null;

    private bool $missingEnvironmentLogged = false;

    public function __construct(
        private readonly Grav $grav,
        private readonly string $userDir
    ) {}

    public function setEnvironmentOverride(?string $name): void
    {
        $this->environmentOverride = $name;
        $this->environmentName = null;
    }

    public function getPluginConfigValue(string $key, mixed $default = null): mixed
    {
        if ($this->environmentOverride !== null) {
            return $this->getScopedPluginConfigValue($key, $default);
        }

        $config = $this->grav['config'] ?? null;

        return $config ? $config->get('plugins.secret-split.' . $key, $default) : $default;
    }

    /**
     * secret-split's own config under the selected scope: the runtime
     * config->get() snapshot only ever shows the booted environment, so for a
     * header-selected scope the env's plugins/secret-split.yaml is overlaid
     * over the base file the same way Grav merges env config at boot (''
     * override = base file only, matching admin-next's "Default" view).
     */
    private function getScopedPluginConfigValue(string $key, mixed $default): mixed
    {
        $data = $this->loadPluginYaml($this->userDir . 'config/plugins/secret-split.yaml');
        if ($this->isValidOverrideEnvironment()) {
            $data = $this->mergeScopedConfig(
                $data,
                $this->loadPluginYaml(
                    $this->userDir . 'env/' . $this->environmentOverride . '/config/plugins/secret-split.yaml'
                )
            );
        }

        $value = $data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadPluginYaml(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $parsed = Yaml::parseFile($path);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Where secret-split's own plugin config is written for the selected
     * scope: admin-next's config write path puts every scoped write into the
     * env's config dir, so the api endpoints must land there too — a named
     * override with an existing env config dir wins; '' / null / missing env
     * dir all stay on the base file (the only write target admin1 has).
     */
    public function getScopedPluginConfigPath(): string
    {
        if ($this->isValidOverrideEnvironment()) {
            return $this->userDir . 'env/' . $this->environmentOverride . '/config/plugins/secret-split.yaml';
        }

        return $this->userDir . 'config/plugins/secret-split.yaml';
    }

    /**
     * secret-split's own config file for the selected scope as it exists on
     * disk — NOT the merged view. env-scope writes must persist only the env
     * delta, never the effective base-overlaid values.
     *
     * @return array<string,mixed>
     */
    public function getScopedPluginConfigRaw(): array
    {
        return $this->loadPluginYaml($this->getScopedPluginConfigPath());
    }

    public function isEnvironmentScopedConfigTarget(): bool
    {
        return $this->getScopedPluginConfigPath() !== $this->userDir . 'config/plugins/secret-split.yaml';
    }

    /**
     * Base-file plugin config — the env layer's parent, used to decide
     * whether a scoped list value differs enough to be persisted.
     *
     * @return array<string,mixed>
     */
    public function getBasePluginConfig(): array
    {
        return $this->loadPluginYaml($this->userDir . 'config/plugins/secret-split.yaml');
    }

    /**
     * A header-selected environment only exists when its env directory does
     * — mirroring admin-next, whose switcher only lists real env dirs. The
     * booted environment (null override) is always "valid" for 1.7 semantics;
     * a named override without a directory degrades consistently to base.
     */
    private function isValidOverrideEnvironment(): bool
    {
        return $this->environmentOverride !== null
            && $this->environmentOverride !== ''
            && is_dir($this->userDir . 'env/' . $this->environmentOverride);
    }

    /**
     * env layer over base layer, with list values replaced atomically —
     * array_replace_recursive() would merge sequential arrays index-wise
     * and corrupt list semantics (e.g. protected_fields [A,B] + env [C]
     * yielding [C,B]).
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $env
     * @return array<string,mixed>
     */
    private function mergeScopedConfig(array $base, array $env): array
    {
        foreach ($env as $key => $value) {
            if (is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !$this->isListArray($value)
                && !$this->isListArray($base[$key])
            ) {
                $base[$key] = $this->mergeScopedConfig($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function isListArray(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * The operator-visible secret-split config for the selected scope — the
     * base file overlaid with the env file, mirroring Grav's boot merge.
     * Without an override this is exactly the base file (the previous view
     * admin1's save interception always worked against).
     */
    public function getScopedPluginConfig(): array
    {
        $base = $this->loadPluginYaml($this->userDir . 'config/plugins/secret-split.yaml');
        $env = $this->environmentOverride;
        if ($env === null || $env === '') {
            return $base;
        }

        if (!$this->isValidOverrideEnvironment()) {
            return $base;
        }

        return $this->mergeScopedConfig(
            $base,
            $this->loadPluginYaml($this->userDir . 'env/' . $env . '/config/plugins/secret-split.yaml')
        );
    }

    public function getEnvironmentName(): string
    {
        if ($this->environmentName !== null) {
            return $this->environmentName;
        }

        if ($this->environmentOverride !== null) {
            return $this->environmentName = $this->environmentOverride;
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

        // A header-selected env that does not exist must not half-scope:
        // scoped config already fell back to base, so env secrets do too.
        if ($this->environmentOverride !== null && !$this->isValidOverrideEnvironment()) {
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
            return $this->resolveInsideUserDir(substr($path, strlen('user://')));
        }

        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $this->resolveInsideUserDir($path);
        }

        // Fully qualified paths are a deliberate config choice (secrets
        // outside the webroot) and are left untouched.
        return $path;
    }

    /**
     * Resolves a user://-relative storage path, refusing to escape the user
     * directory — a configured path is only as trustworthy as the least
     * privileged config writer.
     */
    private function resolveInsideUserDir(string $relative): string
    {
        $parts = [];
        foreach (preg_split('~[/\\\\]+~', $relative) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    $log = $this->grav['log'] ?? null;
                    if (is_object($log) && method_exists($log, 'warning')) {
                        $log->warning('[secret-split] storage path escapes user dir, falling back: ' . $relative);
                    }

                    return $this->userDir . 'secrets.yaml';
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return $this->userDir . implode('/', $parts);
    }
}
