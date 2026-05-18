<?php

declare(strict_types=1);

namespace AWG\Controllers;

use AWG\Services\MenuService;

final class MenuController
{
    public function __construct(private readonly MenuService $menuService)
    {
    }

    public function publicFoodMenu(array $query): array
    {
        $sourceKey = (string) ($query['source'] ?? '');
        return $this->menuService->getFoodMenuBySourceKey($sourceKey);
    }

    public function publicCocktailMenu(): array
    {
        return $this->menuService->getCocktailMenu();
    }

    public function adminMenuImport(array $body): array
    {
        $sourceKey = null;
        if (isset($body['source']) && is_string($body['source'])) {
            $sourceKey = $body['source'];
        }

        return $this->menuService->importMenus($sourceKey);
    }

    public function adminMenuSources(): array
    {
        return $this->menuService->listMenuSources();
    }

    public function adminMenuWorkspace(): array
    {
        return $this->menuService->getAdminWorkspaceOverview();
    }

    public function adminMenuSnapshot(array $query): array
    {
        $sourceKey = (string) ($query['source'] ?? $query['sourceKey'] ?? '');
        return $this->menuService->getAdminSourceSnapshot($sourceKey);
    }

    public function adminMenuExport(array $query): array
    {
        $sourceKey = (string) ($query['source'] ?? $query['sourceKey'] ?? '');
        return $this->menuService->exportSourceMenu($sourceKey);
    }

    public function adminMenuSaveSnapshot(array $body): array
    {
        $sourceKey = (string) ($body['source'] ?? $body['sourceKey'] ?? '');
        $items = $body['items'] ?? [];
        return $this->menuService->saveAdminSourceSnapshot($sourceKey, $items);
    }

    public function adminMenuSaveCategoryOrder(array $body): array
    {
        $sourceKey = (string) ($body['source'] ?? $body['sourceKey'] ?? '');
        $categories = $body['categories'] ?? [];
        return $this->menuService->saveAdminCategoryOrder($sourceKey, $categories);
    }
}