<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Closure;
use Grav\Common\Grav;

final class SecretSplitServices
{
    private ?SecretSplitYamlHelper $yamlHelper = null;

    private ?SecretSplitCatalogBuilder $catalogBuilder = null;

    private ?SecretSplitPathResolver $paths = null;

    private ?SecretSplitStorageManager $storageManager = null;

    private ?SecretSplitStateManager $stateManager = null;

    private ?SecretSplitAdminFlow $adminFlow = null;

    private ?SecretSplitContext $context = null;

    private ?SecretSplitI18n $i18n = null;

    private ?SecretSplitMutationService $mutation = null;

    private ?SecretSplitApplicationService $application = null;

    public function __construct(
        private readonly Grav $grav,
        private readonly string $userDir,
        private readonly Closure $logDebug,
        private readonly Closure $getProtectedFieldCatalog,
        private readonly Closure $collectBlueprintFieldOrder
    ) {}

    public function yamlHelper(): SecretSplitYamlHelper
    {
        if ($this->yamlHelper === null) {
            $this->yamlHelper = new SecretSplitYamlHelper();
        }

        return $this->yamlHelper;
    }

    public function catalogBuilder(): SecretSplitCatalogBuilder
    {
        if ($this->catalogBuilder === null) {
            $this->catalogBuilder = new SecretSplitCatalogBuilder([SecretSplitI18n::class, 'translateStatic']);
        }

        return $this->catalogBuilder;
    }

    public function paths(): SecretSplitPathResolver
    {
        if ($this->paths === null) {
            $this->paths = new SecretSplitPathResolver($this->grav, $this->userDir);
        }

        return $this->paths;
    }

    public function storageManager(): SecretSplitStorageManager
    {
        if ($this->storageManager === null) {
            $this->storageManager = new SecretSplitStorageManager(
                $this->paths(),
                $this->yamlHelper(),
                $this->collectBlueprintFieldOrder
            );
        }

        return $this->storageManager;
    }

    public function stateManager(): SecretSplitStateManager
    {
        if ($this->stateManager === null) {
            $this->stateManager = new SecretSplitStateManager($this->storageManager());
        }

        return $this->stateManager;
    }

    public function context(): SecretSplitContext
    {
        if ($this->context === null) {
            $this->context = new SecretSplitContext(
                $this->paths(),
                $this->getProtectedFieldCatalog
            );
        }

        return $this->context;
    }

    public function adminFlow(): SecretSplitAdminFlow
    {
        if ($this->adminFlow === null) {
            $this->adminFlow = new SecretSplitAdminFlow(
                $this->grav,
                $this->userDir,
                $this->yamlHelper(),
                $this->logDebug,
                $this->context()
            );
        }

        return $this->adminFlow;
    }

    public function i18n(): SecretSplitI18n
    {
        if ($this->i18n === null) {
            $this->i18n = new SecretSplitI18n($this->grav, $this->paths());
        }

        return $this->i18n;
    }

    public function mutation(): SecretSplitMutationService
    {
        if ($this->mutation === null) {
            $this->mutation = new SecretSplitMutationService($this->yamlHelper());
        }

        return $this->mutation;
    }

    public function application(): SecretSplitApplicationService
    {
        if ($this->application === null) {
            $this->application = new SecretSplitApplicationService(
                $this->paths(),
                $this->storageManager(),
                $this->stateManager(),
                $this->mutation(),
                $this->i18n()
            );
        }

        return $this->application;
    }
}
