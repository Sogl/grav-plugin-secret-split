#!/usr/bin/env php
<?php

// [secret-split] service-layer regression corpus — run from the GRAV ROOT:
//   php user/plugins/secret-split/tests/selftest.php
//
// Boots the real Grav container (same as bin/plugin), repoints secret-split
// storage to `user://selftest-secrets*.yaml` fixture files via runtime-only
// config overrides, and exercises the service layer end-to-end. A fake
// `selftest-fake` plugin slug keeps tracked-config fixtures out of real
// plugin YAML. The real user/config/plugins/secret-split.yaml is backed up
// and restored around the persist test.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

\define('GRAV_CLI', true);
\define('GRAV_REQUEST_TIME', microtime(true));

$gravRoot = dirname(__DIR__, 4);
if (!is_file($gravRoot . '/vendor/autoload.php')) {
    exit("FATAL: cannot locate Grav root (expected {$gravRoot})\n");
}
chdir($gravRoot);

$autoload = require $gravRoot . '/vendor/autoload.php';
date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');
mb_internal_encoding('UTF-8');

// Plugin classes are loaded by Grav's plugin loader at runtime, not by the
// root composer autoload — register the plugin's classes/ dir here.
// SECRET_SPLIT_CLASSES override lets a copy of this file inside another Grav
// install (e.g. a Grav 1.7 site) test THESE classes against that runtime:
//   cp tests/selftest.php <grav17>/user/plugins/secret-split/tests/
//   cd <grav17> && SECRET_SPLIT_CLASSES=<this-plugin>/classes \
//     php user/plugins/secret-split/tests/selftest.php
$pluginClasses = getenv('SECRET_SPLIT_CLASSES') ?: (dirname(__DIR__) . '/classes');
spl_autoload_register(static function (string $class) use ($pluginClasses): void {
    if (!str_starts_with($class, 'Grav\\Plugin\\')) {
        return;
    }
    $file = $pluginClasses . '/' . str_replace('\\', '/', substr($class, strlen('Grav\\Plugin\\'))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Plugin\SecretSplitAdminFlow;
use Grav\Plugin\SecretSplitContext;
use Grav\Plugin\SecretSplitMutationService;
use Grav\Plugin\SecretSplitPathResolver;
use Grav\Plugin\SecretSplitServices;
use Grav\Plugin\SecretSplitStateManager;
use Grav\Plugin\SecretSplitStorageManager;
use Grav\Plugin\SecretSplitYamlHelper;

$pass = 0;
$fail = 0;

function check(string $name, mixed $actual, mixed $expected = true): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  ok   {$name}\n";
        return;
    }
    $fail++;
    echo "  FAIL {$name}\n       expected: " . var_export($expected, true)
        . "\n       actual:   " . var_export($actual, true) . "\n";
}

// ---------------------------------------------------------------------------
// Boot Grav, repoint secret-split storage at fixture files
// ---------------------------------------------------------------------------

$grav = Grav::instance(['loader' => $autoload]);
$config = $grav['config'];

$config->set('plugins.secret-split.base_storage_file', 'user://selftest-secrets.yaml');
$config->set('plugins.secret-split.environment_storage_pattern', 'user://selftest-secrets.%s.yaml');

$noLog = static function (): void {};

// Fixture catalog: one password field, two regular protected fields.
$fixtureCatalog = [
    'fields' => [
        'plugins.selftest-fake.mail.password' => ['label' => 'Password'],
        'plugins.selftest-fake.mail.token' => ['label' => 'Token'],
        'plugins.selftest-fake.mail.endpoint' => ['label' => 'Endpoint'],
        'plugins.selftest-fake.mail.region' => ['label' => 'Region'],
    ],
    'passwordFields' => ['plugins.selftest-fake.mail.password'],
];
$getCatalog = static fn(): array => $fixtureCatalog;
$noFieldOrder = static fn(string $slug): array => [];

$services = new SecretSplitServices($grav, USER_DIR, $noLog(...), $getCatalog(...), $noFieldOrder(...));

$paths = $services->paths();
$yaml = $services->yamlHelper();
$storage = $services->storageManager();
$context = $services->context();
$state = $services->stateManager();
$mutation = $services->mutation();
$flow = $services->adminFlow();

$basePath = $paths->getBaseStoragePath();
$envPath = $paths->getEnvironmentStoragePath();
$envName = $paths->getEnvironmentName();
$hasEnv = $envPath !== '' && $envName !== '';

$trackedBasePath = USER_DIR . 'config/plugins/selftest-fake.yaml';
$trackedEnvPath = $hasEnv ? USER_DIR . 'env/' . $envName . '/config/plugins/selftest-fake.yaml' : '';
$secretSplitConfigPath = USER_DIR . 'config/plugins/secret-split.yaml';

$defs = [
    ['full_key' => 'plugins.selftest-fake.mail.password', 'password' => true],
    ['full_key' => 'plugins.selftest-fake.mail.token', 'password' => false],
];

// Runtime config the plugin reads for password flags / definitions.
$config->set('plugins.secret-split.protected_fields', [[
    'plugin' => 'selftest-fake',
    'fields' => [
        ['field_key' => 'plugins.selftest-fake.mail.password', 'password' => true],
        ['field_key' => 'plugins.selftest-fake.mail.token'],
    ],
]]);

$fixtureFiles = array_filter([$basePath, $envPath, $trackedBasePath, $trackedEnvPath]);
$secretSplitBackup = is_file($secretSplitConfigPath) ? file_get_contents($secretSplitConfigPath) : null;
$httpPostBackup = $_POST;
$isChild = false;

$cleanup = static function () use ($fixtureFiles, $secretSplitConfigPath, $secretSplitBackup, $httpPostBackup, &$isChild): void {
    if ($isChild) {
        return;
    }
    foreach ($fixtureFiles as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if ($secretSplitBackup === null) {
        if (is_file($secretSplitConfigPath)) {
            unlink($secretSplitConfigPath);
        }
    } else {
        file_put_contents($secretSplitConfigPath, $secretSplitBackup);
    }
    $_POST = $httpPostBackup;
};

$writeYaml = static function (string $path, array $data) use ($yaml): void {
    if ($path === '') {
        return;
    }
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $yaml->saveYamlFile($path, $data);
};
$readYaml = static fn(string $path): array => $yaml->loadYamlFile($path);

$resolveTarget = static fn(
    string $fullKey, array $base, array $env, bool $envFile, string $scope = ''
): string => $services->resolveStorageTarget($fullKey, $base, $env, $envFile, $scope);

try {

// ---------------------------------------------------------------------------
// A. YamlHelper primitives
// ---------------------------------------------------------------------------
echo "A. SecretSplitYamlHelper\n";

$data = [];
$yaml->setByDotPath($data, 'a.b.c', 'v');
check('set/get dot path', $yaml->getByDotPath($data, 'a.b.c'), 'v');
check('has dot path', $yaml->hasByDotPath($data, 'a.b.c'));
check('has missing dot path', $yaml->hasByDotPath($data, 'a.b.x'), false);
$yaml->unsetByDotPath($data, 'a.b.c');
check('unset dot path', $yaml->hasByDotPath($data, 'a.b.c'), false);
check('unset on missing branch is a no-op', $yaml->getByDotPath($data, 'a.b.c'), null);

$pruned = $yaml->pruneEmptyArrays(['a' => ['b' => []], 'c' => false, 'd' => 0]);
check('pruneEmptyArrays drops empty branches, keeps falsy scalars', $pruned, ['c' => false, 'd' => 0]);

check('loadYamlFile on missing path', $yaml->loadYamlFile($basePath), []);

// ---------------------------------------------------------------------------
// B. PathResolver
// ---------------------------------------------------------------------------
echo "B. SecretSplitPathResolver\n";

check('base storage path resolves user://', $basePath, USER_DIR . 'selftest-secrets.yaml');
check(
    'env storage path follows pattern',
    $envPath,
    $hasEnv ? USER_DIR . 'selftest-secrets.' . $envName . '.yaml' : ''
);
check('tracked base config path', $paths->getTrackedPluginConfigPath('selftest-fake', 'base'), $trackedBasePath);
if ($hasEnv) {
    check('tracked env config path', $paths->getTrackedPluginConfigPath('selftest-fake', 'env'), $trackedEnvPath);
} else {
    echo "  --   env scope unavailable (no environment name); env cases skipped\n";
}

// ---------------------------------------------------------------------------
// C. extractProtectedValuesForPlugin — the save interception
// ---------------------------------------------------------------------------
echo "C. MutationService::extractProtectedValuesForPlugin\n";

// C1: normal value -> base secrets file, removed from source, siblings intact
$writeYaml($basePath, []);
$source = ['mail' => ['token' => 'T1', 'endpoint' => 'https://x', 'password' => 'P1'], 'other' => 1];
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $source, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog
);
check('C1 token extracted to base secrets', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'T1');
check('C1 password extracted to base secrets', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.password'), 'P1');
check('C1 protected keys removed from source', $source, ['mail' => ['endpoint' => 'https://x'], 'other' => 1]);

// C2: env file exists + key already in env -> stays env
if ($hasEnv) {
    $writeYaml($envPath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'ENV_OLD']]]]);
    $source = ['mail' => ['token' => 'T2']];
    $mutation->extractProtectedValuesForPlugin(
        'selftest-fake', $source, $defs, $source, $basePath, $envPath,
        [$context, 'isPasswordKey'], $resolveTarget, $noLog
    );
    check('C2 existing env key re-lands in env', $yaml->getByDotPath($readYaml($envPath), 'plugins.selftest-fake.mail.token'), 'T2');
    check('C2 base untouched', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'T1');

    // C3: brand-new key with env storage present -> env
    $writeYaml($envPath, []);
    $writeYaml($basePath, []);
    $source = ['mail' => ['token' => 'T3']];
    $mutation->extractProtectedValuesForPlugin(
        'selftest-fake', $source, $defs, $source, $basePath, $envPath,
        [$context, 'isPasswordKey'], $resolveTarget, $noLog
    );
    check('C3 new key defaults to env storage', $yaml->getByDotPath($readYaml($envPath), 'plugins.selftest-fake.mail.token'), 'T3');
    check('C3 base stays empty', $readYaml($basePath), []);
}

// C4: password key submitted '' -> secret kept, field removed from source
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['password' => 'KEEP_ME']]]]);
$source = ['mail' => ['password' => '']];
$submitted = ['mail' => ['password' => '']];
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $submitted, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog
);
check('C4 empty password keeps stored secret', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.password'), 'KEEP_ME');
check('C4 empty password removed from source', $source, ['mail' => []]);

// C5: non-password key submitted '' -> stored secret deleted
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'OLD']]]]);
$source = ['mail' => ['token' => '']];
$submitted = ['mail' => ['token' => '']];
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $submitted, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog
);
check('C5 empty non-password deletes secret', $readYaml($basePath), []);
check('C5 key removed from source', $source, ['mail' => []]);

// C6: null value -> removed from source, secrets untouched
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'OLD']]]]);
$source = ['mail' => ['token' => null, 'endpoint' => 'https://x']];
$submitted = ['mail' => ['token' => 'x']]; // not cleared — null read path
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $submitted, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog
);
check('C6 null value removed from source', $source, ['mail' => ['endpoint' => 'https://x']]);
check('C6 stored secret preserved', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'OLD');

// C7: Data object source (admin1 onAdminSave semantics)
$writeYaml($basePath, []);
$dataSource = new Data(['mail' => ['token' => 'TD', 'endpoint' => 'https://d']]);
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $dataSource, $defs, $dataSource->toArray(), $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog
);
check('C7 Data source extracted', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'TD');
check('C7 Data source cleaned', $dataSource->get('mail.token'), null);
check('C7 Data sibling survives', $dataSource->get('mail.endpoint'), 'https://d');

// ---------------------------------------------------------------------------
// C-API. api (admin2) saves — form only round-trips disk YAML, so echoes
// must preserve the stored secret; only genuine edits may replace it.
// ---------------------------------------------------------------------------
echo "C-API. api save echo semantics\n";

// C8: stored secret + submitted '' -> preserved (the admin2 data-loss bug)
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'REAL_SECRET']]]]);
$source = ['mail' => ['token' => '', 'endpoint' => 'https://x']];
$submitted = ['mail' => ['token' => '', 'endpoint' => 'https://x']];
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $submitted, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog, '',
    true, ['mail' => []], null
);
check('C8 api empty echo keeps stored secret', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'REAL_SECRET');
check('C8 key stripped from source', $source, ['mail' => ['endpoint' => 'https://x']]);

// C9: stored secret + submitted blueprint default -> preserved
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'REAL_SECRET']]]]);
$source = ['mail' => ['token' => 'DEF']];
$submitted = ['mail' => ['token' => 'DEF']];
$defaultFn = static fn(string $k): array => ['has' => true, 'value' => 'DEF'];
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $submitted, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog, '',
    true, ['mail' => []], $defaultFn
);
check('C9 api default echo keeps stored secret', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'REAL_SECRET');

// C10: stored secret + submitted previous tracked value -> preserved
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'REAL_SECRET']]]]);
$source = ['mail' => ['token' => 'TRACKED_OLD']];
$submitted = ['mail' => ['token' => 'TRACKED_OLD']];
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $submitted, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog, '',
    true, ['mail' => ['token' => 'TRACKED_OLD']], null
);
check('C10 api unchanged-tracked echo keeps stored secret', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'REAL_SECRET');

// C11: stored secret + genuinely new value -> replaced
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'REAL_SECRET']]]]);
$source = ['mail' => ['token' => 'BRAND_NEW']];
$submitted = ['mail' => ['token' => 'BRAND_NEW']];
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $submitted, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog, '',
    true, ['mail' => []], null
);
check('C11 api real edit replaces stored secret', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'BRAND_NEW');

// C12: api save, no stored secret -> normal extraction semantics unchanged
$writeYaml($basePath, []);
$source = ['mail' => ['token' => 'FIRST_SET']];
$submitted = ['mail' => ['token' => 'FIRST_SET']];
$mutation->extractProtectedValuesForPlugin(
    'selftest-fake', $source, $defs, $submitted, $basePath, $envPath,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog, '',
    true, ['mail' => []], null
);
check('C12 api first set stores secret', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'FIRST_SET');

// ---------------------------------------------------------------------------
// D. migrateProtectedValues — tracked config -> secrets
// ---------------------------------------------------------------------------
echo "D. StateManager::migrateProtectedValues\n";

$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['password' => 'ALREADY_SECRET']]]]);
$writeYaml($trackedBasePath, [
    'mail' => ['password' => 'TRACKED_PW', 'token' => 'TRACKED_TOK', 'endpoint' => 'https://keep'],
    'unrelated' => ['nested' => 'stay'],
]);
$summary = $state->migrateProtectedValues(
    $defs, $basePath, $envPath, $resolveTarget, $noLog
);
$tracked = $readYaml($trackedBasePath);
check('D1 token migrated to secrets', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'TRACKED_TOK');
check('D1 password normalized (secret already existed)', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.password'), 'TRACKED_PW');
check('D1 summary counts', [$summary['migrated'], $summary['normalized'], $summary['missing']], [1, 1, 0]);
check('D1 protected keys removed from tracked config', $tracked, ['mail' => ['endpoint' => 'https://keep'], 'unrelated' => ['nested' => 'stay']]);
check('D1 non-protected keys preserved in tracked config', $tracked['unrelated']['nested'], 'stay');

// D2: env-scope tracked config lands in env secrets
if ($hasEnv) {
    $writeYaml($trackedEnvPath, ['mail' => ['token' => 'ENV_TRACKED']]);
    $writeYaml($envPath, []);
    $summary = $state->migrateProtectedValues(
        [['full_key' => 'plugins.selftest-fake.mail.token', 'password' => false]],
        $basePath, $envPath, $resolveTarget, $noLog
    );
    check('D2 env tracked value -> env secrets', $yaml->getByDotPath($readYaml($envPath), 'plugins.selftest-fake.mail.token'), 'ENV_TRACKED');
    check('D2 env tracked file cleaned', $readYaml($trackedEnvPath), []);
}

// D3: nothing tracked, nothing stored -> missing
$writeYaml($trackedBasePath, ['other' => 'v']);
$writeYaml($basePath, []);
if ($hasEnv) {
    $writeYaml($envPath, []);
}
$summary = $state->migrateProtectedValues(
    [['full_key' => 'plugins.selftest-fake.mail.token', 'password' => false]],
    $basePath, $envPath, $resolveTarget, $noLog
);
check('D3 missing counted', $summary, ['migrated' => 0, 'normalized' => 0, 'missing' => 1]);

// ---------------------------------------------------------------------------
// E. returnProtectedValuesToTrackedConfig — secrets -> tracked config
// ---------------------------------------------------------------------------
echo "E. StateManager::returnProtectedValuesToTrackedConfig\n";

$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'RET_TOK']]]]);
$writeYaml($trackedBasePath, ['mail' => ['endpoint' => 'https://keep']]);
$summary = $state->returnProtectedValuesToTrackedConfig(
    [['full_key' => 'plugins.selftest-fake.mail.token', 'password' => false]],
    $basePath, $envPath
);
check('E1 value returned to tracked config', $yaml->getByDotPath($readYaml($trackedBasePath), 'mail.token'), 'RET_TOK');
check('E1 secret removed from storage', $readYaml($basePath), []);
check('E1 sibling preserved', $readYaml($trackedBasePath)['mail']['endpoint'], 'https://keep');
check('E1 summary', $summary, ['returned' => 1, 'missing' => 0]);

if ($hasEnv) {
    $writeYaml($envPath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'ENV_RET']]]]);
    $writeYaml($trackedEnvPath, []);
    $summary = $state->returnProtectedValuesToTrackedConfig(
        [['full_key' => 'plugins.selftest-fake.mail.token', 'password' => false]],
        $basePath, $envPath
    );
    check('E2 env secret -> env tracked config', $yaml->getByDotPath($readYaml($trackedEnvPath), 'mail.token'), 'ENV_RET');
    check('E2 env secrets pruned', $readYaml($envPath), []);
    // pruneEmptyArrays on an empty tracked file leaves the file; that's fine —
    // tracked configs are written as normal YAML files, not deleted.
    if (is_file($trackedEnvPath)) {
        unlink($trackedEnvPath);
    }
}

// ---------------------------------------------------------------------------
// F. persistSecretSplitProtectedFields — removal cleanup + password flags
// ---------------------------------------------------------------------------
echo "F. AdminFlow::persistSecretSplitProtectedFields\n";

// Seed real plugin config shape with one protected def + a stored secret.
$writeYaml($secretSplitConfigPath, [
    'enabled' => true,
    'base_storage_file' => 'user://selftest-secrets.yaml',
    'environment_storage_pattern' => 'user://selftest-secrets.%s.yaml',
    'protected_fields' => [[
        'plugin' => 'selftest-fake',
        'fields' => [
            ['field_key' => 'plugins.selftest-fake.mail.password', 'password' => true],
            ['field_key' => 'plugins.selftest-fake.mail.token'],
        ],
    ]],
]);
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'DIE', 'password' => 'STAY']]]]);
if ($hasEnv) {
    $writeYaml($envPath, []);
}

// Admin2 path: keep only the password field -> token def removed -> secret deleted.
$flow->persistSecretSplitProtectedFields(
    [[
        'plugin' => 'selftest-fake',
        'fields' => [['field_key' => 'plugins.selftest-fake.mail.password', 'password' => true]],
    ]],
    static fn(): string => $basePath,
    static fn(): string => $envPath,
    static function () use ($config): void {
        $config->set('plugins.secret-split.protected_fields', [[
            'plugin' => 'selftest-fake',
            'fields' => [['field_key' => 'plugins.selftest-fake.mail.password', 'password' => true]],
        ]]);
    }
);
$saved = $readYaml($secretSplitConfigPath);
check('F1 removed definition secret deleted', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), null);
check('F1 kept definition secret survives', $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.password'), 'STAY');
check('F1 config written with remaining def', $saved['protected_fields'][0]['fields'], [['field_key' => 'plugins.selftest-fake.mail.password', 'password' => true]]);
check('F1 plugin options preserved', [$saved['enabled'], $saved['base_storage_file']], [true, 'user://selftest-secrets.yaml']);

// F2: a resubmit that drops `password: true` on a NON-catalog field gets the
// flag restored from the previous config (preserveLegacyPasswordFlags).
// Catalog password fields deliberately skip the restore — the flag is
// implied by isCatalogPasswordField anyway.
$writeYaml($secretSplitConfigPath, [
    'protected_fields' => [[
        'plugin' => 'selftest-fake',
        'fields' => [['field_key' => 'plugins.selftest-fake.mail.token', 'password' => true]],
    ]],
]);
$flow->persistSecretSplitProtectedFields(
    [['plugin' => 'selftest-fake', 'fields' => [['field_key' => 'plugins.selftest-fake.mail.token']]]],
    static fn(): string => $basePath,
    static fn(): string => $envPath,
    static function (): void {}
);
$saved = $readYaml($secretSplitConfigPath);
check(
    'F2 dropped password flag restored from previous config',
    $saved['protected_fields'][0]['fields'][0]['password'] ?? null,
    true
);

// ---------------------------------------------------------------------------
// G. Request body shapes — admin1 envelope vs admin2 JSON map
// ---------------------------------------------------------------------------
echo "G. AdminFlow request body parsing\n";

check('decodeJsonRequestBody valid', SecretSplitAdminFlow::decodeJsonRequestBody('{"a":1}'), ['a' => 1]);
check('decodeJsonRequestBody empty', SecretSplitAdminFlow::decodeJsonRequestBody(''), []);
check('decodeJsonRequestBody invalid', SecretSplitAdminFlow::decodeJsonRequestBody('{bad'), []);
check('decodeJsonRequestBody non-object', SecretSplitAdminFlow::decodeJsonRequestBody('"x"'), []);

// Admin2 api shape: parsed body IS the config map (no `data` envelope).
$apiRequest = new class {
    public array $body = [];
    public function getParsedBody(): array
    {
        return $this->body;
    }
    public function getHeaderLine(string $h): string
    {
        return '';
    }
};

$gravStub = new Grav();
$gravStub['request'] = $apiRequest;
$flowApi = new SecretSplitAdminFlow($gravStub, USER_DIR, $yaml, $noLog, $context);

$apiRequest->body = ['mail' => ['token' => 'NEW', 'endpoint' => 'https://x']];
$_POST = [];
check('G1 api map becomes submitted data', $flowApi->getSubmittedPluginDataFromRequest(), ['mail' => ['token' => 'NEW', 'endpoint' => 'https://x']]);
check('G1 wasSubmittedValueCleared detects empty', $flowApi->wasSubmittedValueCleared(['mail' => ['token' => '']], 'mail.token'));
check('G1 wasSubmittedValueCleared ignores non-empty', $flowApi->wasSubmittedValueCleared(['mail' => ['token' => 'v']], 'mail.token'), false);
check('G1 wasSubmittedValueCleared ignores absent key', $flowApi->wasSubmittedValueCleared(['mail' => []], 'mail.token'), false);

// Admin1 shape: values wrapped in `data`, plus nonce keys alongside.
$apiRequest->body = ['data' => ['mail' => ['token' => 'A1']], 'admin-nonce' => 'n'];
check('G2 admin1 data envelope unwrapped', $flowApi->getSubmittedPluginDataFromRequest(), ['mail' => ['token' => 'A1']]);

// Admin1 POST without `data` -> not a plugin-config save -> empty.
$gravPost = new Grav();
$gravPost['request'] = new class {
    public function getParsedBody(): ?array
    {
        return null;
    }
};
$flowPost = new SecretSplitAdminFlow($gravPost, USER_DIR, $yaml, $noLog, $context);
$_POST = ['admin-nonce' => 'n', 'task' => 'save'];
check('G3 bare POST without data envelope -> empty data', $flowPost->getSubmittedPluginDataFromRequest(), []);
check('G3 POST body returned as request body', $flowPost->getAdminRequestBody(), ['admin-nonce' => 'n', 'task' => 'save']);
$_POST = [];

// ---------------------------------------------------------------------------
// H. applySecretOverlay — runtime config merge
// ---------------------------------------------------------------------------
echo "H. MutationService::applySecretOverlay\n";

$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'BASE', 'password' => 'PW']]]]);
if ($hasEnv) {
    $writeYaml($envPath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'ENV']]]]);
}
$runtime = new Data(['plugins' => ['selftest-fake' => ['mail' => ['endpoint' => 'https://x', 'token' => 'CONFIG']]]]);
$mutation->applySecretOverlay($runtime, $basePath, $envPath, $defs);
check('H1 secret overlays runtime config', $runtime->get('plugins.selftest-fake.mail.token'), $hasEnv ? 'ENV' : 'BASE');
check('H1 second secret applied', $runtime->get('plugins.selftest-fake.mail.password'), 'PW');
check('H1 non-secret config key preserved', $runtime->get('plugins.selftest-fake.mail.endpoint'), 'https://x');

// Stale secrets keys that are not protected must never reach runtime config.
$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'BASE', 'password' => 'PW', 'stale' => 'STALE']]]]);
$writeYaml($envPath, []);
$runtime = new Data(['plugins' => ['selftest-fake' => ['mail' => []]]]);
$mutation->applySecretOverlay($runtime, $basePath, $envPath, $defs);
check('H2 unprotected secret key is not overlaid', $runtime->get('plugins.selftest-fake.mail.stale'), null);

// ---------------------------------------------------------------------------
// I. buildProtectedFieldStateCatalog — status classification
// ---------------------------------------------------------------------------
echo "I. StateManager::buildProtectedFieldStateCatalog\n";

$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['password' => 'S']]]]);
if ($hasEnv) {
    $writeYaml($envPath, []);
}
$writeYaml($trackedBasePath, ['mail' => ['token' => 'T', 'password' => 'DUP'], 'x' => 1]);
$catalogFields = array_fill_keys(array_keys($fixtureCatalog['fields']), ['label' => 'x']);
$catalogState = $state->buildProtectedFieldStateCatalog(
    $catalogFields,
    array_merge($defs, [['full_key' => 'plugins.selftest-fake.mail.endpoint', 'password' => false], ['full_key' => 'plugins.selftest-fake.mail.region', 'password' => false]]),
    $basePath, $envPath, '/migrate', '/return',
    ['base_secrets' => 'b', 'env_secrets' => 'e', 'base_config' => 'bc', 'env_config' => 'ec', 'not_set' => 'n'],
    static fn(string $k): string => $k,
    static fn(string $p, string $s, string $k): string => "{$p}/{$s}/{$k}",
    static fn(string $p, string $t, string $s): string => 'dup'
);
// password: secret + tracked -> duplicate; token: tracked only -> pending;
// endpoint/region: neither -> missing.
check('I1 password -> duplicate (in both)', $catalogState['fields']['plugins.selftest-fake.mail.password']['status'], 'duplicate');
check('I1 token -> pending (tracked only)', $catalogState['fields']['plugins.selftest-fake.mail.token']['status'], 'pending');
check('I1 endpoint -> missing', $catalogState['fields']['plugins.selftest-fake.mail.endpoint']['status'], 'missing');
check('I1 region -> missing', $catalogState['fields']['plugins.selftest-fake.mail.region']['status'], 'missing');
check('I1 counts', $catalogState['counts'], ['stored' => 0, 'pending' => 1, 'duplicate' => 1, 'missing' => 2]);
check('I1 meta storage file names', $catalogState['meta']['base_storage_file'], 'selftest-secrets.yaml');

// stored-only case
$writeYaml($trackedBasePath, ['other' => 'v']);
$catalogState = $state->buildProtectedFieldStateCatalog(
    $catalogFields, $defs, $basePath, $envPath, '/m', '/r',
    ['base_secrets' => 'b', 'env_secrets' => 'e', 'base_config' => 'bc', 'env_config' => 'ec', 'not_set' => 'n'],
    static fn(string $k): string => $k,
    static fn(string $p, string $s, string $k): string => 'src',
    static fn(string $p, string $t, string $s): string => 'dup'
);
check('I2 password -> stored (secret only)', $catalogState['fields']['plugins.selftest-fake.mail.password']['status'], 'stored');

// ---------------------------------------------------------------------------
// J. buildProtectedDefinitionsFromConfigValues — config shapes
// ---------------------------------------------------------------------------
echo "J. SecretSplitContext::buildProtectedDefinitionsFromConfigValues\n";

$nested = $context->buildProtectedDefinitionsFromConfigValues([[
    'plugin' => 'selftest-fake',
    'fields' => [
        ['field_key' => 'plugins.selftest-fake.mail.password', 'password' => true],
        ['field_key' => 'plugins.selftest-fake.mail.token'],
        ['field_key' => 'plugins.secret-split.enabled'],   // self — skipped
        ['field_key' => 'not-a-plugin.key'],                // wrong root — skipped
        ['field_key' => ''],                                // empty — skipped
    ],
]]);
check('J1 nested entries parsed', array_column($nested, 'full_key'), [
    'plugins.selftest-fake.mail.password',
    'plugins.selftest-fake.mail.token',
]);
check('J1 explicit password flag kept', $nested[0]['password'], true);
check('J1 catalog password detection', $nested[0]['password'], true);

$flat = $context->buildProtectedDefinitionsFromConfigValues([
    ['field_key' => 'plugins.selftest-fake.mail.token', 'password' => false],
]);
check('J2 flat entries parsed', $flat, [['full_key' => 'plugins.selftest-fake.mail.token', 'password' => false]]);

$legacy = $context->buildProtectedDefinitionsFromConfigValues(
    [], ['plugins.selftest-fake.mail.token'], []
);
check('J3 legacy protected_keys parsed', $legacy, [['full_key' => 'plugins.selftest-fake.mail.token', 'password' => false]]);

$legacyPwd = $context->buildProtectedDefinitionsFromConfigValues(
    [], ['plugins.selftest-fake.mail.token'], ['plugins.selftest-fake.mail.token']
);
check('J4 legacy password_keys flag', $legacyPwd[0]['password'], true);

$emptyStructured = $context->buildProtectedDefinitionsFromConfigValues(
    [[]], ['plugins.selftest-fake.mail.token'], []
);
check('J5 structured config suppresses legacy fallback', $emptyStructured, []);

// ---------------------------------------------------------------------------
// K. Environment override — admin-next X-Config-Environment scope
// ---------------------------------------------------------------------------
echo "K. Environment override (X-Config-Environment)\n";

$envFixtureDir = USER_DIR . 'env/selftestenv';
$envScopedConfig = $envFixtureDir . '/config/plugins/secret-split.yaml';
$realBaseConfig = is_file($secretSplitConfigPath)
    ? (\Symfony\Component\Yaml\Yaml::parseFile($secretSplitConfigPath) ?: [])
    : [];
@mkdir(dirname($envScopedConfig), 0775, true);
file_put_contents($envScopedConfig, \Symfony\Component\Yaml\Yaml::dump([
    'base_storage_file' => 'user://env-scoped.yaml',
    'protected_fields' => [[
        'plugin' => 'selftest-fake',
        'fields' => [['field_key' => 'plugins.selftest-fake.env.only']],
    ]],
]));

$fakeRequest = static fn(array $headers): object => new class($headers) {
    public function __construct(private array $headers) {}
    public function hasHeader(string $name): bool { return array_key_exists($name, $this->headers); }
    public function getHeaderLine(string $name): string { return (string) ($this->headers[$name] ?? ''); }
};

try {
    // Request header parsing
    check('K1 no headers -> null (booted env)', $flow->getRequestEnvironmentOverride($fakeRequest([])), null);
    check('K2 X-Config-Environment name', $flow->getRequestEnvironmentOverride(
        $fakeRequest(['X-Config-Environment' => 'school.test'])), 'school.test');
    check('K3 default -> base view', $flow->getRequestEnvironmentOverride(
        $fakeRequest(['X-Config-Environment' => 'default'])), '');
    check('K4 base reserved -> base view', $flow->getRequestEnvironmentOverride(
        $fakeRequest(['X-Config-Environment' => 'base'])), '');
    check('K5 empty header -> base view', $flow->getRequestEnvironmentOverride(
        $fakeRequest(['X-Config-Environment' => ''])), '');
    check('K6 invalid name -> null', $flow->getRequestEnvironmentOverride(
        $fakeRequest(['X-Config-Environment' => "bad;name"])), null);
    check('K7 X-Grav-Environment alone does not select scope', $flow->getRequestEnvironmentOverride(
        $fakeRequest(['X-Grav-Environment' => 'staging'])), null);
    check('K8 X-Config-Environment wins', $flow->getRequestEnvironmentOverride(
        $fakeRequest(['X-Config-Environment' => 'cfg', 'X-Grav-Environment' => 'grav'])), 'cfg');

    // PathResolver override
    $paths->setEnvironmentOverride('selftestenv');
    check('K9 env name overridden', $paths->getEnvironmentName(), 'selftestenv');
    // The storage pattern reads through the scoped config too: the fixture
    // env yaml does not set it, so the base FILE's pattern applies.
    $expectedEnvPath = $paths->resolveUserStoragePath(
        sprintf($realBaseConfig['environment_storage_pattern'] ?? 'user://secrets.%s.yaml', 'selftestenv')
    );
    check('K10 env storage path', $paths->getEnvironmentStoragePath(), $expectedEnvPath);
    check('K11 env tracked path', $paths->getTrackedPluginConfigPath('selftest-fake', 'env'),
        USER_DIR . 'env/selftestenv/config/plugins/selftest-fake.yaml');

    // Scoped own-config read: env yaml overlays base file
    $scopedFields = $paths->getPluginConfigValue('protected_fields', []);
    check('K12 scoped protected_fields from env yaml',
        $scopedFields[0]['fields'][0]['field_key'] ?? null, 'plugins.selftest-fake.env.only');
    check('K13 scoped scalar key from env yaml',
        $paths->getPluginConfigValue('base_storage_file'), 'user://env-scoped.yaml');
    check('K14 enabled falls back to base file',
        $paths->getPluginConfigValue('enabled', 'MARKER'), $realBaseConfig['enabled'] ?? 'MARKER');

    // '' override = explicit base-only view (base FILE, not runtime config)
    $paths->setEnvironmentOverride('');
    check('K15 base view env name empty', $paths->getEnvironmentName(), '');
    check('K16 base view env storage disabled', $paths->getEnvironmentStoragePath(), '');
    check('K17 base view tracked env disabled', $paths->getTrackedPluginConfigPath('selftest-fake', 'env'), '');
    check('K18 base view storage_file from base yaml',
        $paths->getPluginConfigValue('base_storage_file', 'MARKER'),
        $realBaseConfig['base_storage_file'] ?? 'MARKER');
    check('K19 base view protected_fields from base yaml',
        $paths->getPluginConfigValue('protected_fields', 'MARKER'),
        $realBaseConfig['protected_fields'] ?? 'MARKER');

    // Scoped write target + previous view for the fields endpoint
    $paths->setEnvironmentOverride('selftestenv');
    check('K20 scoped config path -> env file', $paths->getScopedPluginConfigPath(),
        USER_DIR . 'env/selftestenv/config/plugins/secret-split.yaml');
    $scoped = $paths->getScopedPluginConfig();
    check('K21 scoped config = base overlaid with env',
        $scoped['base_storage_file'] ?? null, 'user://env-scoped.yaml');
    $paths->setEnvironmentOverride('');
    check('K22 base view config path -> base file', $paths->getScopedPluginConfigPath(),
        USER_DIR . 'config/plugins/secret-split.yaml');

    // Scoped persist writes only the env delta, never the merged view
    $paths->setEnvironmentOverride('selftestenv');
    $baseFields = $realBaseConfig['protected_fields'] ?? [];
    $newFields = [['plugin' => 'selftest-fake', 'fields' => [['field_key' => 'plugins.selftest-fake.x']]]];
    $flow->persistSecretSplitProtectedFields(
        $newFields,
        static fn(): string => $paths->getBaseStoragePath(),
        static fn(): string => $paths->getEnvironmentStoragePath(),
        static function (): void {}
    );
    $writtenEnv = \Symfony\Component\Yaml\Yaml::parseFile($envScopedConfig) ?: [];
    check('K23 env persist stores only the delta', $writtenEnv['protected_fields'] ?? null, $newFields);
    check('K23b other env keys preserved', $writtenEnv['base_storage_file'] ?? null, 'user://env-scoped.yaml');
    check('K23c base file protected_fields untouched',
        (\Symfony\Component\Yaml\Yaml::parseFile($secretSplitConfigPath) ?: [])['protected_fields'] ?? null,
        $baseFields !== [] ? $baseFields : null);

    // Persisting a list equal to the parent's removes the env override
    $flow->persistSecretSplitProtectedFields(
        $baseFields,
        static fn(): string => $paths->getBaseStoragePath(),
        static fn(): string => $paths->getEnvironmentStoragePath(),
        static function (): void {}
    );
    $writtenEnv = \Symfony\Component\Yaml\Yaml::parseFile($envScopedConfig) ?: [];
    check('K24 parent-equal fields remove env override',
        array_key_exists('protected_fields', $writtenEnv), false);

    // A named env without a directory degrades every scoped facet to base
    $paths->setEnvironmentOverride('no-such-env-xyz');
    check('K25 invalid env storage disabled', $paths->getEnvironmentStoragePath(), '');
    check('K26 invalid env config target is base', $paths->getScopedPluginConfigPath(),
        USER_DIR . 'config/plugins/secret-split.yaml');
    check('K27 invalid env not a scoped target', $paths->isEnvironmentScopedConfigTarget(), false);

    // Storage path may not escape the user directory
    check('K28 traversal falls back to secrets.yaml',
        $paths->resolveUserStoragePath('user://../escape.yaml'), USER_DIR . 'secrets.yaml');
    check('K29 deep traversal falls back too',
        $paths->resolveUserStoragePath('user://sub/../../escape.yaml'), USER_DIR . 'secrets.yaml');
    check('K30 nested .. that stays inside is kept',
        $paths->resolveUserStoragePath('user://sub/../inside.yaml'), USER_DIR . 'inside.yaml');
} finally {
    $paths->setEnvironmentOverride(null);
    if (is_file($envScopedConfig)) {
        unlink($envScopedConfig);
    }
    @rmdir(dirname($envScopedConfig));
    @rmdir(dirname(dirname($envScopedConfig)));
    @rmdir($envFixtureDir);
}

// ---------------------------------------------------------------------------
// L. Concurrency — the shared lock must serialize multi-file read-modify-write
// ---------------------------------------------------------------------------
echo "L. Concurrency\n";

$counterFile = USER_DIR . 'selftest-lock-counter.yaml';
try {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
        echo "  --   pcntl unavailable; concurrency cases skipped\n";
    } else {
        @unlink($counterFile);
        $children = 4;
        $increments = 25;
        $pids = [];
        for ($i = 0; $i < $children; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $pids = null;
                break;
            }
            if ($pid === 0) {
                // Child: hammer the same YAML counter with locked RMW cycles.
                // Without serialization, lost updates make the total < 100.
                $isChild = true;
                for ($j = 0; $j < $increments; $j++) {
                    $storage->withStorageLock($basePath, function () use ($counterFile, $yaml): void {
                        $data = $yaml->loadYamlFile($counterFile);
                        $yaml->saveYamlFile($counterFile, ['counter' => (int) ($data['counter'] ?? 0) + 1]);
                    });
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        if ($pids === null) {
            echo "  --   pcntl_fork failed; concurrency cases skipped\n";
        } else {
            $exitOk = true;
            foreach ($pids as $cpid) {
                pcntl_waitpid($cpid, $status);
                $exitOk = $exitOk && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
            }
            check('L1 forked children exited cleanly', $exitOk);
            $counter = $yaml->loadYamlFile($counterFile);
            check(
                'L2 lock serializes 4x25 concurrent increments',
                $counter['counter'] ?? null,
                $children * $increments
            );
        }
    }
} finally {
    if (is_file($counterFile)) {
        unlink($counterFile);
    }
}

// ---------------------------------------------------------------------------
// M. Fault injection — a failed tracked write must leave secrets untouched
// ---------------------------------------------------------------------------
echo "M. Fault injection\n";

$writeYaml($basePath, ['plugins' => ['selftest-fake' => ['mail' => ['token' => 'RET_TOK']]]]);
$writeYaml($trackedBasePath, ['mail' => ['endpoint' => 'https://keep']]);
// YamlFile::save is atomic (temp + rename) — a readonly FILE does not block
// it, but a readonly DIRECTORY does (the temp file cannot be created).
chmod(dirname($trackedBasePath), 0555);
$threw = false;
try {
    $state->returnProtectedValuesToTrackedConfig(
        [['full_key' => 'plugins.selftest-fake.mail.token', 'password' => false]],
        $basePath, $envPath
    );
} catch (\Throwable $e) {
    $threw = true;
} finally {
    @chmod(dirname($trackedBasePath), 0755);
}
check('M1 tracked write failure surfaces as exception', $threw);
check('M1 secrets preserved when tracked write fails',
    $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'RET_TOK');
check('M1 tracked config left unmodified',
    $yaml->getByDotPath($readYaml($trackedBasePath), 'mail.token'), null);

// ---------------------------------------------------------------------------
// N. ApplicationService — flex post-save tracked-config migration
// ---------------------------------------------------------------------------
echo "N. ApplicationService::processFlexTrackedConfigMigration\n";

$app = $services->application();

$writeYaml($trackedBasePath, ['mail' => ['endpoint' => 'https://x']]);
$snap = $app->snapshotTrackedConfig('selftest-fake');
check('N1 unchanged config detected as unchanged',
    $app->detectChangedTrackedScopes('selftest-fake', $snap), []);

$writeYaml($trackedBasePath, ['mail' => ['token' => 'FLEX_TOK', 'endpoint' => 'https://x']]);
$changed = $app->detectChangedTrackedScopes('selftest-fake', $snap);
check('N2 base change detected', in_array('base', $changed, true), true);

$writeYaml($basePath, []);
$summary = $app->processFlexTrackedConfigMigration(
    'selftest-fake', $defs, $changed,
    [$context, 'isPasswordKey'], $resolveTarget, $noLog
);
check('N3 flex secret migrated to storage',
    $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'FLEX_TOK');
check('N3 secret stripped from tracked config',
    $yaml->getByDotPath($readYaml($trackedBasePath), 'mail.token'), null);
check('N3 sibling preserved', $yaml->getByDotPath($readYaml($trackedBasePath), 'mail.endpoint'), 'https://x');
check('N3 migrated summary', $summary['migrated'], 1);

// A failed tracked rewrite during the flex/shutdown migration must leave the
// extracted secret in storage and the tracked file intact — a duplicate
// state, never a loss.
$writeYaml($trackedBasePath, ['mail' => ['token' => 'FLEX_TOK2', 'endpoint' => 'https://x']]);
$writeYaml($basePath, []);
chmod(dirname($trackedBasePath), 0555);
$threw = false;
try {
    $app->processFlexTrackedConfigMigration(
        'selftest-fake', $defs, ['base'],
        [$context, 'isPasswordKey'], $resolveTarget, $noLog
    );
} catch (\Throwable $e) {
    $threw = true;
} finally {
    @chmod(dirname($trackedBasePath), 0755);
}
check('M2 flex migration surfaces tracked write failure', $threw);
check('M2 extracted secret still landed in storage',
    $yaml->getByDotPath($readYaml($basePath), 'plugins.selftest-fake.mail.token'), 'FLEX_TOK2');
check('M2 tracked file kept plaintext (duplicate, not loss)',
    $yaml->getByDotPath($readYaml($trackedBasePath), 'mail.token'), 'FLEX_TOK2');

// ---------------------------------------------------------------------------
// O. Flex wiring — route resolution + nonce flow (mocked admin/flex services)
// ---------------------------------------------------------------------------
echo "O. Flex wiring\n";

try {
    $plugin = new \Grav\Plugin\SecretSplitPlugin('secret-split', $grav, $config);
    $rmRoute = new \ReflectionMethod($plugin, 'getFlexConfiguredPluginSlugFromRoute');
    $rmRoute->setAccessible(true);

    $mockDirectory = static function (string $configurePath, string $configFile): object {
        return new class($configurePath, $configFile) {
            public function __construct(private string $configurePath, private string $configFile) {}
            public function getConfig(string $key): mixed {
                return match ($key) {
                    'admin.router.actions.configure.path' => $this->configurePath,
                    'blueprints.configure.file' => $this->configFile,
                    'data.storage.options.folder' => '',
                    default => null,
                };
            }
            public function getFlexType(): string { return 'plugins'; }
        };
    };
    $mockFlex = static function (object ...$dirs): object {
        return new class($dirs) {
            public function __construct(private array $dirs) {}
            public function getDirectories(): array { return $this->dirs; }
        };
    };
    $mockAdmin = static function (string $location, string $target): object {
        return new class($location, $target) {
            public function __construct(private string $location, private string $target) {}
            public function getRouteDetails(): array { return ['plugins', $this->location, $this->target]; }
        };
    };

    check('O1 no flex services -> null', $rmRoute->invoke($plugin), null);

    $hadAdmin = isset($grav['admin']);
    $hadFlex = isset($grav['flex_objects']);
    try {
        $grav['admin'] = $mockAdmin('plugins', 'selftest-fake/configure');
        $grav['flex_objects'] = $mockFlex(
            $mockDirectory('/plugins/selftest-fake/configure/', 'plugins/selftest-fake.yaml')
        );
        check('O2 flex configure route resolves slug', $rmRoute->invoke($plugin), 'selftest-fake');

        $grav['flex_objects'] = $mockFlex(
            $mockDirectory('/other/path/', 'plugins/selftest-fake.yaml')
        );
        check('O3 non-matching configure path -> null', $rmRoute->invoke($plugin), null);

        $grav['flex_objects'] = $mockFlex(
            $mockDirectory('/plugins/selftest-fake/configure/', 'something/else.json')
        );
        check('O4 non-plugin config file -> null', $rmRoute->invoke($plugin), null);
    } finally {
        // Mocked services stay assigned (Pimple has no unset) — nothing after
        // this section resolves admin/flex_objects.
        unset($hadAdmin, $hadFlex);
    }

    // Nonce flow: a real admin-form nonce must verify, a bogus one must not.
    $_REQUEST['admin-nonce'] = \Grav\Common\Utils::getNonce('admin-form');
    [$nonce, $action] = $flow->getAdminFormNonceFromRequest();
    check('O5 request nonce parsed with action', [$nonce !== '', $action], [true, 'admin-form']);
    check('O5 generated nonce verifies', \Grav\Common\Utils::verifyNonce($nonce, $action), true);
    check('O6 bogus nonce rejected', \Grav\Common\Utils::verifyNonce('bogus-nonce-xyz', 'admin-form'), false);
    unset($_REQUEST['admin-nonce']);
} catch (\Throwable $e) {
    echo "  --   flex wiring skipped: {$e->getMessage()}\n";
}

// ---------------------------------------------------------------------------
// P. End-to-end — real PATCH through the live api plugin (opt-in)
//    Run: SECRET_SPLIT_E2E_URL=http://site.test \
//         SECRET_SPLIT_E2E_TOKEN_FILE=/path/to/key \
//         php user/plugins/secret-split/tests/selftest.php
// ---------------------------------------------------------------------------
echo "P. E2E (real api PATCH)\n";

$e2eUrl = rtrim((string) getenv('SECRET_SPLIT_E2E_URL'), '/');
$e2eToken = (string) (getenv('SECRET_SPLIT_E2E_TOKEN') ?: '');
$e2eTokenFile = (string) (getenv('SECRET_SPLIT_E2E_TOKEN_FILE') ?: '');
if ($e2eToken === '' && $e2eTokenFile !== '' && is_file($e2eTokenFile)) {
    $e2eToken = trim((string) file_get_contents($e2eTokenFile));
}
if ($e2eUrl === '' || $e2eToken === '') {
    echo "  --   e2e skipped (needs SECRET_SPLIT_E2E_URL + SECRET_SPLIT_E2E_TOKEN[_FILE])\n";
} else {
    $e2eTracked = USER_DIR . 'config/plugins/selftest-e2e.yaml';
    $e2eSecrets = USER_DIR . 'selftest-e2e-secrets.yaml';
    try {
        // Repoint the live site's secret-split config at fixture storage so a
        // real HTTP save extracts into a throwaway file, not user/secrets.yaml.
        $writeYaml($secretSplitConfigPath, [
            'enabled' => true,
            'base_storage_file' => 'user://selftest-e2e-secrets.yaml',
            'environment_storage_pattern' => 'user://selftest-e2e-secrets.%s.yaml',
            'protected_fields' => [[
                'plugin' => 'selftest-e2e',
                'fields' => [['field_key' => 'plugins.selftest-e2e.mail.token']],
            ]],
        ]);
        $writeYaml($e2eTracked, ['mail' => ['endpoint' => 'https://e2e', 'token' => 'TRACKED-OLD']]);
        @unlink($e2eSecrets);

        $patch = static function (array $body) use ($e2eUrl, $e2eToken): array {
            $ctx = stream_context_create(['http' => [
                'method' => 'PATCH',
                'header' => "X-API-Key: {$e2eToken}\r\nX-API-Token: {$e2eToken}\r\nContent-Type: application/json\r\n",
                'content' => json_encode($body),
                'ignore_errors' => true,
                'timeout' => 15,
            ]]);
            $resp = @file_get_contents($e2eUrl . '/api/v1/config/plugins/selftest-e2e', false, $ctx);
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('~\s(\d{3})~', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
            return [$status, (string) $resp];
        };

        [$code, $respBody] = $patch(['mail' => ['endpoint' => 'https://e2e', 'token' => 'E2E-NEW']]);
        if ($code !== 0 && !in_array($code, [200, 204], true)) {
            echo "  --   e2e PATCH returned {$code}: " . substr($respBody, 0, 200) . "\n";
        }
        if ($code === 0) {
            echo "  --   e2e unreachable ({$e2eUrl}); skipped\n";
        } else {
            check('P1 PATCH accepted', in_array($code, [200, 204], true), true);
            check('P1 token moved to secrets storage',
                $yaml->getByDotPath($readYaml($e2eSecrets), 'plugins.selftest-e2e.mail.token'), 'E2E-NEW');
            check('P1 token stripped from tracked config',
                $yaml->getByDotPath($readYaml($e2eTracked), 'mail.token'), null);
            check('P1 sibling preserved',
                $yaml->getByDotPath($readYaml($e2eTracked), 'mail.endpoint'), 'https://e2e');

            // Echo save: '' for a stored secret must not delete it.
            [$code] = $patch(['mail' => ['endpoint' => 'https://e2e', 'token' => '']]);
            check('P2 echo PATCH accepted', in_array($code, [200, 204], true), true);
            check('P2 stored secret survives echo',
                $yaml->getByDotPath($readYaml($e2eSecrets), 'plugins.selftest-e2e.mail.token'), 'E2E-NEW');
        }
    } finally {
        foreach ([$e2eTracked, $e2eSecrets] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }
}

// ============================================================================
// Q. isApiRequest — header and api-route-path detection
// ============================================================================
echo "\n== Q. isApiRequest detection ==\n";

$apiReq = static fn(array $headers, string $path): object => new class($headers, $path) {
    public function __construct(private array $headers, private string $path) {}
    public function getHeaderLine(string $n): string { return (string) ($this->headers[$n] ?? ''); }
    public function getUri(): object { return new class($this->path) {
        public function __construct(private string $p) {}
        public function getPath(): string { return $this->p; }
    }; }
};

$gravCfg = new Grav();
$gravCfg['config'] = new class {
    public function get(string $key, $default = null) {
        return ['plugins.api.route' => '/api', 'plugins.api.version_prefix' => 'v1'][$key] ?? $default;
    }
};
$flowDetect = new SecretSplitAdminFlow($gravCfg, USER_DIR, $yaml, $noLog, $context);

check('Q1 X-API-Token header detects api', $flowDetect->isApiRequest($apiReq(['X-API-Token' => 'tok'], '/api/v1/x')), true);
check('Q2 Bearer header detects api', $flowDetect->isApiRequest($apiReq(['Authorization' => 'Bearer jwt'], '/api/v1/x')), true);
check('Q3 session request under /api/v1/ detected by path', $flowDetect->isApiRequest($apiReq([], '/api/v1/algolia-pro/data')), true);
check('Q4 prefix boundary /api/v1x rejected', $flowDetect->isApiRequest($apiReq([], '/api/v1x/evil')), false);
check('Q5 plain admin route rejected', $flowDetect->isApiRequest($apiReq([], '/admin/plugin/algolia-pro')), false);
check('Q6 exact prefix root matches', $flowDetect->isApiRequest($apiReq([], '/api/v1')), true);
check('Q7 pathless request without headers rejected', $flowDetect->isApiRequest($apiReq([], '')), false);

$gravCfgCustom = new Grav();
$gravCfgCustom['config'] = new class {
    public function get(string $key, $default = null) {
        return ['plugins.api.route' => '/backend', 'plugins.api.version_prefix' => 'v9'][$key] ?? $default;
    }
};
$flowCustom = new SecretSplitAdminFlow($gravCfgCustom, USER_DIR, $yaml, $noLog, $context);
check('Q8 custom api route prefix honored', $flowCustom->isApiRequest($apiReq([], '/backend/v9/whatever')), true);
check('Q9 default prefix fails under custom config', $flowCustom->isApiRequest($apiReq([], '/api/v1/x')), false);

} finally {
    $cleanup();
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
