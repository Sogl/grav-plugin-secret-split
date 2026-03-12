<?php

declare(strict_types=1);

namespace Grav\Plugin;

final class SecretSplitYamlHelper
{
    use SecretSplitYamlTrait {
        loadYamlFile as public;
        saveYamlFile as public;
        saveSecretsYamlFile as public;
        hasByDotPath as public;
        setByDotPath as public;
        getByDotPath as public;
        unsetByDotPath as public;
        extractOrderedConfigPaths as public;
        mergeOrderedPaths as public;
        buildOrderMapFromPaths as public;
        reorderConfigByOrderMap as public;
        pruneEmptyArrays as public;
    }
}
