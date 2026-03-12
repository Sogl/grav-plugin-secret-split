<?php

declare(strict_types=1);

namespace Grav\Plugin;

use RocketTheme\Toolbox\File\YamlFile;

final class SecretSplitCatalogBuilder
{
    /** @var callable */
    private $translate;

    public function __construct(callable $translate)
    {
        $this->translate = $translate;
    }

    public function readPluginDisplayName(string $pluginDir, string $pluginSlug): string
    {
        $blueprintPath = $pluginDir . '/blueprints.yaml';
        if (!is_file($blueprintPath)) {
            return $pluginSlug;
        }

        $file = YamlFile::instance($blueprintPath);
        try {
            $data = $file->content();
        } finally {
            $file->free();
        }

        $name = trim((string) ($data['name'] ?? ''));

        return $name !== '' ? $name : $pluginSlug;
    }

    /**
     * @return array<string,array{label:string,type:string}>
     */
    public function collectConfigFieldsForPlugin(string $pluginDir, string $pluginSlug): array
    {
        $catalog = [];

        foreach ($this->getBlueprintFieldSets($pluginDir, $pluginSlug) as $fieldset) {
            $this->walkBlueprintFields(
                $pluginSlug,
                $fieldset['fields'],
                $catalog,
                [],
                ''
            );
        }

        return $this->disambiguateDuplicateFieldLabels($pluginSlug, $catalog);
    }

    /**
     * @param array<string,array{label:string,type:string}> $catalog
     * @return array<string,array{label:string,type:string}>
     */
    private function disambiguateDuplicateFieldLabels(string $pluginSlug, array $catalog): array
    {
        $groupedKeys = [];

        foreach ($catalog as $fullKey => $fieldInfo) {
            $groupedKeys[$fieldInfo['label']][] = $fullKey;
        }

        foreach ($groupedKeys as $label => $keys) {
            if (count($keys) < 2) {
                continue;
            }

            foreach ($keys as $fullKey) {
                $suffix = $this->buildDuplicateFieldLabelSuffix($pluginSlug, $fullKey, $keys);
                $catalog[$fullKey]['label'] = sprintf('%s [%s]', $label, $suffix);
            }
        }

        return $catalog;
    }

    /**
     * @param string[] $groupKeys
     */
    private function buildDuplicateFieldLabelSuffix(string $pluginSlug, string $fullKey, array $groupKeys): string
    {
        $prefix = 'plugins.' . $pluginSlug . '.';
        $relativeKey = str_starts_with($fullKey, $prefix) ? substr($fullKey, strlen($prefix)) : $fullKey;

        $groupHasAdminVariant = false;
        foreach ($groupKeys as $groupKey) {
            $groupRelative = str_starts_with($groupKey, $prefix) ? substr($groupKey, strlen($prefix)) : $groupKey;
            if (str_starts_with($groupRelative, 'admin.') || str_contains($groupRelative, '.admin.')) {
                $groupHasAdminVariant = true;
                break;
            }
        }

        $isAdminVariant = str_starts_with($relativeKey, 'admin.') || str_contains($relativeKey, '.admin.');
        if ($groupHasAdminVariant) {
            return $this->translateText(
                $isAdminVariant
                    ? 'PLUGIN_SECRET_SPLIT.LABEL_CONTEXT.ADMIN'
                    : 'PLUGIN_SECRET_SPLIT.LABEL_CONTEXT.SITE'
            );
        }

        return $this->humanizeFieldPath($relativeKey);
    }

    private function humanizeFieldPath(string $relativeKey): string
    {
        $parts = array_filter(explode('.', $relativeKey), static fn($part) => $part !== '');
        $parts = array_map(static function (string $part): string {
            return ucwords(str_replace('_', ' ', $part));
        }, $parts);

        return implode(' / ', $parts);
    }

    /**
     * @return array<int,array{fields: array<string,mixed>}>
     */
    private function getBlueprintFieldSets(string $pluginDir, string $pluginSlug): array
    {
        $sets = [];

        $mainBlueprint = $pluginDir . '/blueprints.yaml';
        if (is_file($mainBlueprint)) {
            $file = YamlFile::instance($mainBlueprint);
            try {
                $data = $file->content();
            } finally {
                $file->free();
            }

            $fields = $data['form']['fields'] ?? null;
            if (is_array($fields)) {
                $sets[] = ['fields' => $fields];
            }
        }

        foreach (glob($pluginDir . '/blueprints/flex/*.yaml') ?: [] as $flexBlueprint) {
            $file = YamlFile::instance($flexBlueprint);
            try {
                $data = $file->content();
            } finally {
                $file->free();
            }

            $configureFile = (string) ($data['blueprints']['configure']['file'] ?? '');
            if ($configureFile !== 'config://plugins/' . $pluginSlug . '.yaml') {
                continue;
            }

            $fields = $data['blueprints']['configure']['fields'] ?? null;
            if (is_array($fields)) {
                $sets[] = ['fields' => $fields];
            }
        }

        return $sets;
    }

    /**
     * @param array<string,mixed> $fields
     * @param array<string,array{label:string,type:string}> $catalog
     * @param string[] $trail
     */
    private function walkBlueprintFields(string $pluginSlug, array $fields, array &$catalog, array $trail, string $prefix): void
    {
        foreach ($fields as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }

            $childFields = $definition['fields'] ?? null;

            if (str_starts_with($name, '.') || isset($definition['unset@'])) {
                continue;
            }

            if (isset($definition['import@']) && !is_array($childFields)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? '');
            $label = $this->translateBlueprintText((string) ($definition['label'] ?? $definition['title'] ?? ''));
            $nextTrail = $trail;

            if (in_array($type, ['tab', 'fieldset', 'section'], true) && $label !== '') {
                $nextTrail[] = $label;
            }

            if (is_array($childFields)) {
                $this->walkBlueprintFields($pluginSlug, $childFields, $catalog, $nextTrail, $prefix);
                continue;
            }

            if ($type === '' || in_array($type, ['display', 'spacer', 'columns', 'column', 'tabs', 'list', 'array', 'value', 'ignore'], true)) {
                continue;
            }

            $fieldKey = $prefix !== '' ? $prefix . '.' . $name : $name;
            $fullKey = 'plugins.' . $pluginSlug . '.' . $fieldKey;
            $fieldLabel = $label !== '' ? $label : $fieldKey;
            $fullLabel = implode(' / ', array_filter([...$nextTrail, $fieldLabel], static fn($item) => $item !== ''));
            if ($fullLabel === '') {
                $fullLabel = $fieldKey;
            }

            $catalog[$fullKey] = [
                'label' => $fullLabel,
                'type' => $type,
            ];
        }
    }

    private function translateBlueprintText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return $this->translateText($value);
    }

    private function translateText(string $key): string
    {
        $translate = $this->translate;

        return (string) $translate($key);
    }
}
