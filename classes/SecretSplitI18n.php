<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Language\Language;

final class SecretSplitI18n
{
    /** @var array<string,string>|null */
    private ?array $jsTranslations = null;

    public function __construct(
        private readonly Grav $grav,
        private readonly SecretSplitPathResolver $paths
    ) {}

    public function translate(string $key, array $replacements = []): string
    {
        $text = self::translateStatic($key, $this->getPreferredTranslationLanguages());

        if ($replacements !== []) {
            $text = strtr($text, $replacements);
        }

        return $text;
    }

    /**
     * @param string[]|null $languages
     */
    public static function translateStatic(string $key, ?array $languages = null): string
    {
        $grav = Grav::instance();
        $language = $grav['language'] ?? null;
        $languageService = $language instanceof Language ? $language : null;

        $preferredLanguages = $languages;
        if (!is_array($preferredLanguages) || $preferredLanguages === []) {
            $preferredLanguages = [];

            $user = $grav['user'] ?? null;
            if (is_object($user) && property_exists($user, 'language')) {
                $userLanguage = (string) ($user->language ?? '');
                if ($userLanguage !== '') {
                    $preferredLanguages[] = $userLanguage;
                }
            }

            if ($languageService !== null) {
                $activeLanguage = (string) $languageService->getLanguage();
                if ($activeLanguage !== '') {
                    $preferredLanguages[] = $activeLanguage;
                }

                $defaultLanguage = (string) $languageService->getDefault();
                if ($defaultLanguage !== '') {
                    $preferredLanguages[] = $defaultLanguage;
                }
            }

            $preferredLanguages[] = 'en';
        }

        if (class_exists(\Grav\Plugin\Admin\Admin::class)) {
            $translated = \Grav\Plugin\Admin\Admin::translate($key);
            if (is_string($translated) && $translated !== '' && $translated !== $key) {
                return $translated;
            }
        }

        if ($languageService === null) {
            return $key;
        }

        foreach (array_values(array_unique($preferredLanguages)) as $lang) {
            $translated = $languageService->getTranslation($lang, $key, true);
            if (is_string($translated) && $translated !== '') {
                return $translated;
            }
        }

        return $key;
    }

    /**
     * @return string[]
     */
    public function getPreferredTranslationLanguages(): array
    {
        $languages = [];

        $user = $this->grav['user'] ?? null;
        if (is_object($user) && property_exists($user, 'language')) {
            $userLanguage = (string) ($user->language ?? '');
            if ($userLanguage !== '') {
                $languages[] = $userLanguage;
            }
        }

        $language = $this->grav['language'] ?? null;
        if ($language instanceof Language) {
            $activeLanguage = (string) $language->getLanguage();
            if ($activeLanguage !== '') {
                $languages[] = $activeLanguage;
            }

            $defaultLanguage = (string) $language->getDefault();
            if ($defaultLanguage !== '') {
                $languages[] = $defaultLanguage;
            }
        }

        $languages[] = 'en';

        return array_values(array_unique(array_filter($languages, static fn($lang) => is_string($lang) && $lang !== '')));
    }

    public function buildDuplicateSourceLabel(string $pluginSlug, string $trackedScope, string $secretScope): string
    {
        return $this->buildSourceLabel($pluginSlug, $trackedScope, 'tracked')
            . ' · '
            . $this->buildSourceLabel($pluginSlug, $secretScope, 'secrets');
    }

    public function buildSourceLabel(string $pluginSlug, string $scope, string $kind): string
    {
        $path = match ($kind) {
            'secrets' => $scope === 'env' ? $this->paths->getEnvironmentStoragePath() : $this->paths->getBaseStoragePath(),
            'tracked' => $this->paths->getTrackedPluginConfigPath($pluginSlug, $scope),
            default => '',
        };

        if ($path === '') {
            return $this->translate('PLUGIN_SECRET_SPLIT.SOURCE.NOT_SET');
        }

        $key = match ([$kind, $scope]) {
            ['secrets', 'base'] => 'PLUGIN_SECRET_SPLIT.SOURCE.BASE_SECRETS',
            ['secrets', 'env'] => 'PLUGIN_SECRET_SPLIT.SOURCE.ENV_SECRETS',
            ['tracked', 'base'] => 'PLUGIN_SECRET_SPLIT.SOURCE.BASE_CONFIG',
            ['tracked', 'env'] => 'PLUGIN_SECRET_SPLIT.SOURCE.ENV_CONFIG',
            default => 'PLUGIN_SECRET_SPLIT.SOURCE.NOT_SET',
        };

        return $this->translate($key) . ' (' . basename($path) . ')';
    }

    /**
     * @return array{
     *   base_secrets:string,
     *   env_secrets:string,
     *   base_config:string,
     *   env_config:string,
     *   not_set:string
     * }
     */
    public function getSourceLabels(): array
    {
        return [
            'base_secrets' => $this->translate('PLUGIN_SECRET_SPLIT.SOURCE.BASE_SECRETS'),
            'env_secrets' => $this->translate('PLUGIN_SECRET_SPLIT.SOURCE.ENV_SECRETS'),
            'base_config' => $this->translate('PLUGIN_SECRET_SPLIT.SOURCE.BASE_CONFIG'),
            'env_config' => $this->translate('PLUGIN_SECRET_SPLIT.SOURCE.ENV_CONFIG'),
            'not_set' => $this->translate('PLUGIN_SECRET_SPLIT.SOURCE.NOT_SET'),
        ];
    }

    /**
     * @return array<string,string>
     */
    public function getJsTranslations(): array
    {
        if (is_array($this->jsTranslations)) {
            return $this->jsTranslations;
        }

        $this->jsTranslations = [
            'status_title' => $this->translate('PLUGIN_SECRET_SPLIT.STATUS.TITLE'),
            'overview_stored' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.STORED'),
            'overview_pending' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.PENDING'),
            'overview_duplicate' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.DUPLICATE'),
            'overview_missing' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.MISSING'),
            'migrate_to_file' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.MIGRATE_TO_FILE'),
            'migrate_note_to_file' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.NOTE_TO_FILE'),
            'return_to_config' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.RETURN_TO_CONFIG'),
            'return_note_from_file' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.RETURN_NOTE_FROM_FILE'),
            'save_first_note' => $this->translate('PLUGIN_SECRET_SPLIT.OVERVIEW.SAVE_FIRST_NOTE'),
        ];

        return $this->jsTranslations;
    }
}
