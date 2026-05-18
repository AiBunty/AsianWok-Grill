<?php

declare(strict_types=1);

namespace AWG\Controllers;

use AWG\Services\MenuManagementService;

final class MenuManagementController
{
    public function __construct(private readonly MenuManagementService $service)
    {
    }

    public function editorLoad(array $query): array
    {
        return $this->service->editorLoad((string) ($query['menuType'] ?? 'menu_a'));
    }

    public function editorSaveChanges(array $body): array
    {
        $changes = is_array($body['changes'] ?? null) ? $body['changes'] : [];
        return $this->service->saveChanges((string) ($body['menuType'] ?? 'menu_a'), $changes);
    }

    public function editorAddRow(array $body): array
    {
        $row = is_array($body['row'] ?? null) ? $body['row'] : $body;
        return $this->service->addRow((string) ($body['menuType'] ?? 'menu_a'), $row);
    }

    public function editorDeleteRows(array $body): array
    {
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        return $this->service->deleteRows((string) ($body['menuType'] ?? 'menu_a'), $ids);
    }

    public function editorSetVisibility(array $body): array
    {
        $ids = is_array($body['ids'] ?? null) ? $body['ids'] : [];
        $isVisible = (bool) ($body['isVisible'] ?? $body['isAvailable'] ?? true);
        return $this->service->setVisibility((string) ($body['menuType'] ?? 'menu_a'), $ids, $isVisible);
    }

    public function editorUploadImage(array $body): array
    {
        $menuType = (string) ($body['menuType'] ?? $_POST['menuType'] ?? 'menu_a');
        $itemId = (int) ($body['itemId'] ?? $_POST['itemId'] ?? 0);
        $file = $_FILES['file'] ?? [];
        if (!is_array($file)) {
            $file = [];
        }

        return $this->service->uploadEditorImage($menuType, $itemId, $file);
    }

    public function editorImagePreview(array $query): array
    {
        $menuType = (string) ($query['menuType'] ?? 'menu_a');
        $itemId = (int) ($query['itemId'] ?? 0);
        return $this->service->getEditorImagePreview($menuType, $itemId);
    }

    public function designerLoad(array $query): array
    {
        return $this->service->designerLoad((string) ($query['menuType'] ?? 'menu_a'));
    }

    public function designerSaveCategoryOrder(array $body): array
    {
        $categories = is_array($body['categories'] ?? null) ? $body['categories'] : [];
        return $this->service->saveCategoryOrder((string) ($body['menuType'] ?? 'menu_a'), $categories);
    }

    public function designerSaveItemOrder(array $body): array
    {
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        return $this->service->saveItemOrder((string) ($body['menuType'] ?? 'menu_a'), $items);
    }

    public function designerToggleCategory(array $body): array
    {
        return $this->service->toggleCategory(
            (string) ($body['menuType'] ?? 'menu_a'),
            (string) ($body['categoryName'] ?? ''),
            (bool) ($body['isActive'] ?? true)
        );
    }

    public function designerToggleItem(array $body): array
    {
        return $this->service->toggleItem(
            (string) ($body['menuType'] ?? 'menu_a'),
            (int) ($body['id'] ?? 0),
            (bool) ($body['isAvailable'] ?? true)
        );
    }

    public function designerCloneCategory(array $body): array
    {
        return $this->service->cloneCategory(
            (string) ($body['menuType'] ?? 'menu_a'),
            (string) ($body['sourceCategory'] ?? ''),
            (string) ($body['targetCategory'] ?? ''),
            (bool) ($body['cloneItems'] ?? true)
        );
    }

    public function importPreview(array $body): array
    {
        $menuType = (string) ($body['menuType'] ?? $_POST['menuType'] ?? 'menu_a');
        $file = $_FILES['file'] ?? [];
        if (!is_array($file)) {
            $file = [];
        }

        return $this->service->importPreview($menuType, $file);
    }

    public function importExecute(array $body, array $auth): array
    {
        $actor = (string) ($auth['user']['username'] ?? $auth['user']['id'] ?? 'system');
        return $this->service->importExecute(
            (string) ($body['menuType'] ?? 'menu_a'),
            (string) ($body['tmpPath'] ?? ''),
            (bool) ($body['createCategories'] ?? true),
            (bool) ($body['takeSnapshot'] ?? false),
            $actor
        );
    }

    public function export(array $query): array
    {
        return $this->service->exportWorkbook((string) ($query['menuType'] ?? 'menu_a'));
    }

    public function template(array $query): array
    {
        return $this->service->templateWorkbook((string) ($query['menuType'] ?? 'menu_a'));
    }
}
