<?php

namespace Grav\Plugin;

use Grav\Common\Utils;
use RocketTheme\Toolbox\File\YamlFile;

trait SecretSplitYamlTrait
{
    private function loadYamlFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $file = YamlFile::instance($path);
        try {
            $content = $file->content();
        } finally {
            $file->free();
        }

        return is_array($content) ? $content : [];
    }

    private function saveYamlFile(string $path, array $data): void
    {
        if ($path === '') {
            return;
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $file = YamlFile::instance($path);
        try {
            $file->save($data);
        } finally {
            $file->free();
        }
    }

    private function saveSecretsYamlFile(string $path, array $data): void
    {
        if ($path === '') {
            return;
        }

        $data = $this->pruneEmptyArrays($data);
        if ($data === []) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        $this->saveYamlFile($path, $data);
    }

    private function hasByDotPath(array $data, string $path): bool
    {
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return false;
            }

            $current = $current[$part];
        }

        return true;
    }

    private function setByDotPath(array &$data, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $current = &$data;

        foreach ($parts as $part) {
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }

            $current = &$current[$part];
        }

        $current = $value;
    }

    private function getByDotPath(array $data, string $path): mixed
    {
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }

    private function unsetByDotPath(array &$data, string $path): void
    {
        $parts = explode('.', $path);
        $last = array_pop($parts);
        $current = &$data;

        foreach ($parts as $part) {
            if (!isset($current[$part]) || !is_array($current[$part])) {
                return;
            }

            $current = &$current[$part];
        }

        if ($last !== null && is_array($current) && array_key_exists($last, $current)) {
            unset($current[$last]);
        }
    }

    private function extractOrderedConfigPaths(array $data, string $prefix = ''): array
    {
        if ($data === [] || $this->isListArray($data)) {
            return $prefix !== '' ? [$prefix] : [];
        }

        $paths = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $paths = array_merge($paths, $this->extractOrderedConfigPaths($value, $path));
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @param string[] $primary
     * @param string[] $secondary
     * @return string[]
     */
    private function mergeOrderedPaths(array $primary, array $secondary): array
    {
        $result = array_values(array_unique($primary));

        foreach ($secondary as $index => $path) {
            if (in_array($path, $result, true)) {
                continue;
            }

            $inserted = false;

            for ($probe = $index - 1; $probe >= 0; $probe--) {
                $previous = $secondary[$probe] ?? null;
                if ($previous === null) {
                    continue;
                }

                $position = array_search($previous, $result, true);
                if ($position === false) {
                    continue;
                }

                array_splice($result, $position + 1, 0, [$path]);
                $inserted = true;
                break;
            }

            if ($inserted) {
                continue;
            }

            for ($probe = $index + 1, $count = count($secondary); $probe < $count; $probe++) {
                $next = $secondary[$probe] ?? null;
                if ($next === null) {
                    continue;
                }

                $position = array_search($next, $result, true);
                if ($position === false) {
                    continue;
                }

                array_splice($result, $position, 0, [$path]);
                $inserted = true;
                break;
            }

            if (!$inserted) {
                $result[] = $path;
            }
        }

        return $result;
    }

    /**
     * @param string[] $paths
     * @return array<string,string[]>
     */
    private function buildOrderMapFromPaths(array $paths): array
    {
        $orderMap = [];

        foreach ($paths as $path) {
            $parts = array_values(array_filter(explode('.', $path), static fn($part) => $part !== ''));
            $prefix = '';

            foreach ($parts as $part) {
                $orderMap[$prefix] ??= [];
                if (!in_array($part, $orderMap[$prefix], true)) {
                    $orderMap[$prefix][] = $part;
                }

                $prefix = $prefix === '' ? $part : $prefix . '.' . $part;
            }
        }

        return $orderMap;
    }

    /**
     * @param array<string|int,mixed> $data
     * @param array<string,string[]> $orderMap
     * @return array<string|int,mixed>
     */
    private function reorderConfigByOrderMap(array $data, array $orderMap, string $prefix = ''): array
    {
        if ($this->isListArray($data)) {
            $result = [];
            foreach ($data as $index => $value) {
                $result[$index] = is_array($value)
                    ? $this->reorderConfigByOrderMap($value, $orderMap, $prefix)
                    : $value;
            }

            return $result;
        }

        $order = $orderMap[$prefix] ?? [];
        $result = $order !== [] ? Utils::sortArrayByArray($data, $order) : $data;

        foreach ($result as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $childPrefix = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $result[$key] = $this->reorderConfigByOrderMap($value, $orderMap, $childPrefix);
        }

        return $result;
    }

    /**
     * @param array<mixed> $value
     */
    private function isListArray(array $value): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function pruneEmptyArrays(array $data): array
    {
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $value = $this->pruneEmptyArrays($value);
            if ($value === []) {
                unset($data[$key]);
                continue;
            }

            $data[$key] = $value;
        }

        return $data;
    }
}
