<?php

declare(strict_types=1);

namespace Grav\Plugin\SecretSplit\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\TranslatesAdminLabels;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\SecretSplitI18n;
use Grav\Plugin\SecretSplitPlugin;
use Grav\Plugin\SecretSplitServices;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin2 API endpoints for Secret Split: the field-marking state payload,
 * `protected_fields` persistence, and the migrate/return actions that lived
 * behind admin1 task URLs on Grav 1.7.
 *
 * The controller builds its own SecretSplitServices graph (same shared
 * factory the plugin uses) — Grav keeps plugin instances out of the DI
 * container, and every operation below is service-level anyway.
 */
class SecretSplitApiController extends AbstractApiController
{
    use TranslatesAdminLabels;

    private ?SecretSplitServices $services = null;

    private function services(ServerRequestInterface $request): SecretSplitServices
    {
        if ($this->services === null) {
            $this->services = SecretSplitServices::create($this->grav);
            $this->services->applyRequestEnvironment($request);
        }

        return $this->services;
    }

    public function state(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'admin.super');

        return ApiResponse::create($this->buildState($this->primeAdminLanguages($request), $request));
    }

    public function saveFields(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'admin.super');

        $body = $this->getRequestBody($request);
        $fields = $body['protected_fields'] ?? null;
        if (!is_array($fields)) {
            $fields = [];
        }

        // Rebuild strictly: plugin -> fields list, dropping empty plugins.
        $clean = [];
        foreach ($fields as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $plugin = trim((string) ($entry['plugin'] ?? ''));
            if ($plugin === '') {
                continue;
            }
            $out = ['plugin' => $plugin, 'fields' => []];
            foreach ((array) ($entry['fields'] ?? []) as $fieldEntry) {
                if (!is_array($fieldEntry)) {
                    continue;
                }
                $fullKey = trim((string) ($fieldEntry['field_key'] ?? ''));
                if ($fullKey === '' || !str_starts_with($fullKey, 'plugins.')) {
                    continue;
                }
                $item = ['field_key' => $fullKey];
                if (!empty($fieldEntry['password'])) {
                    $item['password'] = true;
                }
                $out['fields'][] = $item;
            }
            if ($out['fields'] !== []) {
                $clean[] = $out;
            }
        }

        $languages = $this->primeAdminLanguages($request);
        $services = $this->services($request);
        $services->adminFlow()->persistSecretSplitProtectedFields(
            $clean,
            [$services->paths(), 'getBaseStoragePath'],
            [$services->paths(), 'getEnvironmentStoragePath'],
            function (array $currentConfig): void {
                $this->grav['config']->set('plugins.secret-split', $currentConfig);
            }
        );

        return ApiResponse::create($this->buildState($languages, $request));
    }

    public function migrate(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runAction($request, 'migrate');
    }

    public function returnSecrets(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runAction($request, 'return');
    }

    private function runAction(ServerRequestInterface $request, string $action): ResponseInterface
    {
        $this->requirePermission($request, 'admin.super');

        $languages = $this->primeAdminLanguages($request);
        $services = $this->services($request);
        $services->i18n()->setPreferredLanguages($languages);

        $logDebug = function (string $message, array $context = []): void {
            if (!(bool) $this->config->get('plugins.secret-split.debug_logging', false)) {
                return;
            }
            $logger = $this->grav['log'] ?? null;
            if (is_object($logger)) {
                $logger->debug('[secret-split] ' . $message, $context);
            }
        };

        if ($action === 'migrate') {
            $summary = $services->application()->migrateProtectedValues(
                $services->context()->getProtectedDefinitions(),
                [$services, 'resolveStorageTarget'],
                $logDebug
            );
            $duplicates = ($summary['normalized'] ?? 0) > 0
                ? SecretSplitI18n::translateStatic('PLUGIN_SECRET_SPLIT.MESSAGES.DUPLICATE_SUFFIX', $languages)
                : '';
            $message = SecretSplitI18n::translateStatic('PLUGIN_SECRET_SPLIT.MESSAGES.MIGRATED', $languages);
            $message = strtr($message, [
                '%migrated%' => (string) ($summary['migrated'] ?? 0),
                '%duplicates%' => strtr($duplicates, ['%normalized%' => (string) ($summary['normalized'] ?? 0)]),
            ]);
        } else {
            $summary = $services->application()->returnProtectedValuesToTrackedConfig(
                $services->context()->getProtectedDefinitions()
            );
            $message = SecretSplitI18n::translateStatic('PLUGIN_SECRET_SPLIT.MESSAGES.RETURNED', $languages);
            $message = strtr($message, ['%returned%' => (string) ($summary['returned'] ?? 0)]);
        }

        return ApiResponse::create([
            'state' => $this->buildState($languages, $request),
            'message' => $message,
        ]);
    }

    /**
     * @param string[] $languages
     * @return array<string,mixed>
     */
    private function buildState(array $languages, ServerRequestInterface $request): array
    {
        $services = $this->services($request);
        $services->i18n()->setPreferredLanguages($languages);

        $catalog = SecretSplitPlugin::getProtectedFieldCatalog();
        $translate = static fn(string $key): string => SecretSplitI18n::translateStatic($key, $languages);
        $states = $services->application()->buildProtectedFieldStateCatalog(
            $catalog['fields'] ?? [],
            $services->context()->getProtectedDefinitions(),
            '/secret-split/migrate',
            '/secret-split/return',
            $translate
        );

        return [
            'catalog' => $catalog,
            'states' => $states,
            'protected_fields' => (array) $services->context()->getPluginConfigValue('protected_fields', []),
            'strings' => array_merge(
                $services->i18n()->getJsTranslations(),
                [
                    'page_title' => $translate('PLUGIN_SECRET_SPLIT.PAGE.TITLE'),
                    'subtitle' => $translate('PLUGIN_SECRET_SPLIT.PAGE.SUBTITLE'),
                    'loading' => $translate('PLUGIN_SECRET_SPLIT.PAGE.LOADING'),
                    'saved' => $translate('PLUGIN_SECRET_SPLIT.PAGE.SAVED'),
                    'no_fields' => $translate('PLUGIN_SECRET_SPLIT.PAGE.NO_FIELDS'),
                    'migrate_confirm' => $translate('PLUGIN_SECRET_SPLIT.PAGE.MIGRATE_CONFIRM'),
                    'return_confirm' => $translate('PLUGIN_SECRET_SPLIT.PAGE.RETURN_CONFIRM'),
                    'empty_overview' => $translate('PLUGIN_SECRET_SPLIT.PAGE.EMPTY_OVERVIEW'),
                    'pick_plugin' => $translate('PLUGIN_SECRET_SPLIT.PAGE.PICK_PLUGIN'),
                    'choose_plugin' => $translate('PLUGIN_SECRET_SPLIT.PAGE.CHOOSE_PLUGIN'),
                ]
            ),
        ];
    }
}
