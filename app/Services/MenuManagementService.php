<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Database;
use AWG\Repositories\MenuManagementRepository;
use AWG\Repositories\MenuRepository;
use AWG\Support\SimpleXlsx;
use RuntimeException;
use Throwable;

final class MenuManagementService
{
    private const MENU_TYPES = ['menu_a', 'menu_b', 'menu_c'];
    private const SHEET_MAP = [
        'menu_a' => 'MENU_A',
        'menu_b' => 'MENU_B',
        'menu_c' => 'MENU_C',
    ];

    private const STANDARD_COLUMNS = [
        'source_row',
        'category',
        'item_name',
        'description',
        'image_url',
        'is_available',
        'is_chef_special',
        'is_veg',
        'is_nonveg',
        'is_jain',
        'is_universal',
        'spice_level',
        'primary_diet',
        'pricing_mode',
        'price_veg',
        'price_jain',
        'price_chicken',
        'price_mutton',
        'price_basa',
        'price_prawns',
        'price_surmai',
        'price_pomfret',
        'price_crab',
        'price_egg',
        'price_half',
        'price_full',
        'price_plain',
        'price_butter',
        'price_medium',
        'price_large',
        'price_direct',
        'category_sort_order',
        'item_sort_order',
    ];

    private const BOOL_COLUMNS = ['is_available', 'is_chef_special', 'is_veg', 'is_nonveg', 'is_jain', 'is_universal'];

    private const COLUMN_ALIASES = [
        'source row' => 'source_row',
        'source_row' => 'source_row',
        'row' => 'source_row',
        'category' => 'category',
        'category name' => 'category',
        'category_name' => 'category',
        'item' => 'item_name',
        'item name' => 'item_name',
        'item_name' => 'item_name',
        'name' => 'item_name',
        'description' => 'description',
        'desc' => 'description',
        'image' => 'image_url',
        'image_url' => 'image_url',
        'image url' => 'image_url',
        'is_available' => 'is_available',
        'available' => 'is_available',
        'visible' => 'is_available',
        'is_chef_special' => 'is_chef_special',
        'chef_special' => 'is_chef_special',
        'chef special' => 'is_chef_special',
        'chef\'s special' => 'is_chef_special',
        'is_veg' => 'is_veg',
        'veg' => 'price_veg',
        'veg rate' => 'price_veg',
        'is_nonveg' => 'is_nonveg',
        'nonveg' => 'is_nonveg',
        'is_jain' => 'is_jain',
        'jain' => 'price_jain',
        'jain rate' => 'price_jain',
        'is_universal' => 'is_universal',
        'universal' => 'is_universal',
        'spice_level' => 'spice_level',
        'spice level' => 'spice_level',
        'spice' => 'spice_level',
        'primary_diet' => 'primary_diet',
        'pricing_mode' => 'pricing_mode',
        'pricing mode' => 'pricing_mode',
        'price_veg' => 'price_veg',
        'veg price' => 'price_veg',
        'price_jain' => 'price_jain',
        'jain price' => 'price_jain',
        'price_chicken' => 'price_chicken',
        'chicken' => 'price_chicken',
        'chicken price' => 'price_chicken',
        'price_mutton' => 'price_mutton',
        'mutton' => 'price_mutton',
        'mutton price' => 'price_mutton',
        'price_basa' => 'price_basa',
        'fish' => 'price_basa',
        'basa price' => 'price_basa',
        'price_prawns' => 'price_prawns',
        'prawn' => 'price_prawns',
        'prawns' => 'price_prawns',
        'prawns price' => 'price_prawns',
        'price_surmai' => 'price_surmai',
        'surmai' => 'price_surmai',
        'surmai price' => 'price_surmai',
        'price_pomfret' => 'price_pomfret',
        'pomfret' => 'price_pomfret',
        'pomfret price' => 'price_pomfret',
        'price_crab' => 'price_crab',
        'crab' => 'price_crab',
        'crab price' => 'price_crab',
        'price_egg' => 'price_egg',
        'egg' => 'price_egg',
        'egg price' => 'price_egg',
        'price_half' => 'price_half',
        'half' => 'price_half',
        'half price' => 'price_half',
        'price_full' => 'price_full',
        'full' => 'price_full',
        'full price' => 'price_full',
        'price_plain' => 'price_plain',
        'plain' => 'price_plain',
        'plain price' => 'price_plain',
        'price_butter' => 'price_butter',
        'butter' => 'price_butter',
        'butter price' => 'price_butter',
        'price_medium' => 'price_medium',
        'medium' => 'price_medium',
        'medium price' => 'price_medium',
        'price_large' => 'price_large',
        'large' => 'price_large',
        'large price' => 'price_large',
        'price_direct' => 'price_direct',
        'price' => 'price_direct',
        'category_sort_order' => 'category_sort_order',
        'category order' => 'category_sort_order',
        'item_sort_order' => 'item_sort_order',
        'item order' => 'item_sort_order',
    ];

    private const UNIVERSAL_CATEGORIES = [
        'mocktails',
        'shakes & smoothies',
        'iced tea',
        'lemonades',
        'cold ones',
        'breads',
        'dessert',
        'desserts',
    ];

    public function editorLoad(string $menuType): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $this->seedFromLegacyIfEmpty($menuType, $repository);
        $items = $repository->listMenuItems($menuType);
        $categories = $repository->listCategories($menuType);

        return [
            'ok' => true,
            'menuType' => $menuType,
            'items' => array_map(fn (array $row): array => $this->mapItemForApi($row), $items),
            'categories' => array_map(fn (array $row): array => $this->mapCategoryForApi($row), $categories),
        ];
    }

    public function saveChanges(string $menuType, array $changes): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $normalized = [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $normalized[] = $this->normalizeEditorPayload($change, $menuType);
        }

        $updatedCount = $repository->saveChanges($menuType, $normalized);
        return [
            'ok' => true,
            'updatedCount' => $updatedCount,
        ];
    }

    public function addRow(string $menuType, array $row): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $payload = $this->normalizeEditorPayload($row, $menuType);
        $payload['item_sort_order'] = (int) ($payload['item_sort_order'] ?? 0);

        $category = (string) ($payload['category'] ?? 'Uncategorized');
        $categoryRow = $repository->ensureCategory($menuType, $category);
        $payload['category_sort_order'] = (int) ($categoryRow['sort_order'] ?? 0);

        $newId = $repository->insertItem($menuType, $payload);
        $repository->replaceVariantsForItem($newId, is_array($row['variants'] ?? null) ? $row['variants'] : []);

        return ['ok' => true, 'id' => $newId];
    }

    public function deleteRows(string $menuType, array $ids): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $deleted = $repository->deleteRows($menuType, $ids);
        return ['ok' => true, 'deleted' => $deleted];
    }

    public function setVisibility(string $menuType, array $ids, bool $isVisible): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $updated = $repository->setItemVisibility($menuType, $ids, $isVisible);
        return ['ok' => true, 'updated' => $updated];
    }

    public function uploadEditorImage(string $menuType, int $itemId, array $file): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        if ($itemId <= 0) {
            return $this->validationError('Valid itemId is required.');
        }

        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            return $this->validationError('Image upload failed. Please choose an image and retry.');
        }

        $tmpPath = $file['tmp_name'] ?? null;
        if (!is_string($tmpPath) || $tmpPath === '' || !is_file($tmpPath)) {
            return $this->validationError('Uploaded image is invalid.');
        }

        if (!function_exists('imagewebp')) {
            return [
                'ok' => false,
                'error' => 'SERVER_IMAGE_SUPPORT_MISSING',
                'message' => 'Server image processing is not available (GD with WEBP is required).',
            ];
        }

        $webpBinary = $this->convertUploadedImageToWebp($tmpPath);
        if ($webpBinary === null) {
            return $this->validationError('Unsupported image format. Use JPG, PNG, GIF or WEBP.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $saved = $repository->updateUploadedImage($menuType, $itemId, $webpBinary, 'image/webp');
        if ($saved !== true) {
            return [
                'ok' => false,
                'error' => 'IMAGE_UPLOAD_SAVE_FAILED',
                'message' => 'Unable to store uploaded image.',
            ];
        }

        $preview = $repository->getUploadedImage($menuType, $itemId);
        $dataUri = $this->buildUploadedImageDataUri($preview);

        return [
            'ok' => true,
            'itemId' => $itemId,
            'uploadedImageDataUri' => $dataUri,
            'uploadedImageMime' => 'image/webp',
            'effectiveImageSource' => 'uploaded',
        ];
    }

    public function getEditorImagePreview(string $menuType, int $itemId): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        if ($itemId <= 0) {
            return $this->validationError('Valid itemId is required.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $preview = $repository->getUploadedImage($menuType, $itemId);
        $dataUri = $this->buildUploadedImageDataUri($preview);

        return [
            'ok' => true,
            'itemId' => $itemId,
            'uploadedImageDataUri' => $dataUri,
            'hasUploadedImage' => $dataUri !== null,
        ];
    }

    public function designerLoad(string $menuType): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $this->seedFromLegacyIfEmpty($menuType, $repository);
        $items = $repository->listMenuItems($menuType);
        $categories = $repository->listCategories($menuType);

        $itemsByCategory = [];
        foreach ($items as $item) {
            $category = (string) ($item['category'] ?? 'Uncategorized');
            $itemsByCategory[$category][] = [
                'id' => (int) ($item['id'] ?? 0),
                'itemName' => (string) ($item['item_name'] ?? ''),
                'isAvailable' => ((int) ($item['is_available'] ?? 1)) === 1,
                'itemSortOrder' => (int) ($item['item_sort_order'] ?? 0),
            ];
        }

        $payloadCategories = [];
        foreach ($categories as $category) {
            $name = (string) ($category['name'] ?? '');
            $payloadCategories[] = [
                'name' => $name,
                'sortOrder' => (int) ($category['sort_order'] ?? 0),
                'isActive' => ((int) ($category['is_active'] ?? 1)) === 1,
                'items' => $itemsByCategory[$name] ?? [],
            ];
        }

        return [
            'ok' => true,
            'menuType' => $menuType,
            'categories' => $payloadCategories,
        ];
    }

    public function saveCategoryOrder(string $menuType, array $categories): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $updated = $repository->saveCategoryOrder($menuType, $categories);
        return ['ok' => true, 'updated' => $updated];
    }

    public function saveItemOrder(string $menuType, array $items): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $updated = $repository->saveItemOrder($menuType, $items);
        return ['ok' => true, 'updated' => $updated];
    }

    public function toggleCategory(string $menuType, string $name, bool $isActive): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $updated = $repository->toggleCategory($menuType, $name, $isActive);
        return ['ok' => true, 'updated' => $updated];
    }

    public function toggleItem(string $menuType, int $id, bool $isAvailable): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $updated = $repository->toggleItem($menuType, $id, $isAvailable);
        return ['ok' => true, 'updated' => $updated];
    }

    public function cloneCategory(string $menuType, string $sourceName, string $targetName, bool $cloneItems): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }
        if (trim($sourceName) === '' || trim($targetName) === '') {
            return $this->validationError('sourceCategory and targetCategory are required.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $result = $repository->cloneCategory($menuType, $sourceName, $targetName, $cloneItems);
        return [
            'ok' => true,
            'categoryCreated' => (bool) ($result['categoryCreated'] ?? false),
            'itemsCloned' => (int) ($result['itemsCloned'] ?? 0),
        ];
    }

    public function importPreview(string $menuType, array $file): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_file($file['tmp_name'])) {
            return $this->validationError('Excel file is required.');
        }

        $sheets = SimpleXlsx::readSheets($file['tmp_name']);
        $sheetName = self::SHEET_MAP[$menuType] ?? '';
        $rows = $sheets[$sheetName] ?? [];

        if ($rows === []) {
            // Fallback for workbooks that use generic names like "Sheet"/"Sheet1".
            $firstSheetName = array_key_first($sheets);
            if (is_string($firstSheetName) && $firstSheetName !== '') {
                $sheetName = $firstSheetName;
                $rows = $sheets[$firstSheetName] ?? [];
            }
        }

        if ($rows === []) {
            return [
                'ok' => true,
                'menuType' => $menuType,
                'sheet_found' => false,
                'total_rows' => 0,
                'data_rows' => 0,
                'blank_rows_skipped' => 0,
                'mapped_columns' => [],
                'unmapped_columns' => [],
                'sample_rows' => [],
                'categories' => [],
                'variant_columns' => [],
                'previewSummary' => 'No sheet found for ' . $sheetName,
            ];
        }

        $header = array_map(static fn ($v): string => trim((string) $v), $rows[0] ?? []);
        $columnMap = [];
        $mappedColumns = [];
        $unmappedColumns = [];

        foreach ($header as $index => $name) {
            if ($name === '') {
                continue;
            }

            $canonical = $this->canonicalColumn($name);
            if ($canonical !== null) {
                $columnMap[$index] = $canonical;
                $mappedColumns[$name] = $canonical;
            } else {
                $unmappedColumns[] = $name;
            }
        }

        $blankRowsSkipped = 0;
        $dataRows = [];
        $categories = [];
        foreach (array_slice($rows, 1) as $rowIndex => $cells) {
            $isBlank = true;
            foreach ($cells as $value) {
                if (trim((string) $value) !== '') {
                    $isBlank = false;
                    break;
                }
            }

            if ($isBlank) {
                $blankRowsSkipped++;
                continue;
            }

            $mapped = [];
            foreach ($columnMap as $index => $target) {
                $mapped[$target] = $cells[$index] ?? '';
            }

            foreach ($unmappedColumns as $name) {
                $idx = array_search($name, $header, true);
                if ($idx === false) {
                    continue;
                }
                $mapped['__unknown__' . $name] = $cells[(int) $idx] ?? '';
            }

            $category = trim((string) ($mapped['category'] ?? ''));
            if ($category !== '') {
                $categories[$category] = true;
            }

            $sourceRow = $mapped['source_row'] ?? '';
            $mapped['source_row'] = $sourceRow !== '' ? $sourceRow : ($rowIndex + 2);
            $dataRows[] = $mapped;
        }

        $variantColumns = $this->detectVariantColumns($header, $unmappedColumns, $rows);
        $tmpPath = $this->writePreviewStage([
            'menuType' => $menuType,
            'sheetName' => $sheetName,
            'header' => $header,
            'mappedColumns' => $mappedColumns,
            'unmappedColumns' => $unmappedColumns,
            'variantColumns' => $variantColumns,
            'rows' => $dataRows,
            'createdAt' => date('c'),
        ]);

        return [
            'ok' => true,
            'menuType' => $menuType,
            'sheet_found' => true,
            'tmpPath' => $tmpPath,
            'total_rows' => count($rows),
            'data_rows' => count($dataRows),
            'blank_rows_skipped' => $blankRowsSkipped,
            'mapped_columns' => $mappedColumns,
            'unmapped_columns' => $unmappedColumns,
            'sample_rows' => array_slice($dataRows, 0, 8),
            'categories' => array_values(array_keys($categories)),
            'variant_columns' => $variantColumns,
            'previewSummary' => 'Preview ready for ' . strtoupper($menuType) . ' with ' . count($dataRows) . ' data rows.',
        ];
    }

    public function importExecute(string $menuType, string $tmpPath, bool $createCategories, bool $takeSnapshot, string $actor): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $stage = $this->readPreviewStage($tmpPath);
        if (($stage['menuType'] ?? '') !== $menuType) {
            return $this->validationError('Preview data does not match requested menuType.');
        }

        $rows = is_array($stage['rows'] ?? null) ? $stage['rows'] : [];
        $variantColumns = is_array($stage['variantColumns'] ?? null) ? $stage['variantColumns'] : [];

        $repository = new MenuManagementRepository(Database::connection());

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];
        $errors = [];

        try {
            $repository->begin();

            if ($takeSnapshot) {
                $snapshotRows = $repository->listMenuItems($menuType);
                $repository->saveSnapshot($menuType, 'Pre-import snapshot', $snapshotRows, $actor);
            }

            foreach ($rows as $index => $row) {
                if (!is_array($row)) {
                    $skipped++;
                    continue;
                }

                $normalized = $this->normalizeImportRow($menuType, $row, $variantColumns);
                $itemName = trim((string) ($normalized['item_name'] ?? ''));
                if ($itemName === '') {
                    $skipped++;
                    $warnings[] = 'Row ' . ($index + 2) . ' skipped: item_name missing.';
                    continue;
                }

                $categoryName = trim((string) ($normalized['category'] ?? 'Uncategorized'));
                if ($categoryName === '') {
                    $categoryName = 'Uncategorized';
                }

                $category = null;
                if ($createCategories) {
                    $category = $repository->ensureCategory($menuType, $categoryName);
                } else {
                    $load = $repository->listCategories($menuType);
                    foreach ($load as $candidate) {
                        if (strcasecmp((string) ($candidate['name'] ?? ''), $categoryName) === 0) {
                            $category = $candidate;
                            break;
                        }
                    }
                }

                if (!is_array($category)) {
                    $skipped++;
                    $warnings[] = 'Row ' . ($index + 2) . ' skipped: unresolved category "' . $categoryName . '".';
                    continue;
                }

                $normalized['category_sort_order'] = (int) ($category['sort_order'] ?? 0);

                $existing = $repository->findItemForImport(
                    $menuType,
                    (string) ($normalized['category'] ?? ''),
                    (string) ($normalized['item_name'] ?? ''),
                    $normalized['source_row'] !== null ? (int) $normalized['source_row'] : null
                );

                if (is_array($existing)) {
                    $repository->updateItemById($menuType, (int) ($existing['id'] ?? 0), $normalized);
                    $repository->replaceVariantsForItem((int) ($existing['id'] ?? 0), $normalized['variants'] ?? []);
                    $updated++;
                } else {
                    $newId = $repository->insertItem($menuType, $normalized);
                    $repository->replaceVariantsForItem($newId, $normalized['variants'] ?? []);
                    $inserted++;
                }
            }

            $repository->commit();
        } catch (Throwable $exception) {
            $repository->rollBack();
            $errors[] = $exception->getMessage();
            return [
                'ok' => false,
                'type' => $menuType,
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        return [
            'ok' => true,
            'type' => $menuType,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    public function exportWorkbook(string $menuType): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $repository = new MenuManagementRepository(Database::connection());
        $items = $repository->listMenuItems($menuType);

        $variantLabels = [];
        foreach ($items as $item) {
            foreach (($item['variants'] ?? []) as $variant) {
                $label = trim((string) ($variant['variantLabel'] ?? ''));
                if ($label !== '') {
                    $variantLabels[$label] = true;
                }
            }
        }

        $variantColumns = array_values(array_keys($variantLabels));
        sort($variantColumns, SORT_NATURAL | SORT_FLAG_CASE);

        $header = array_merge(self::STANDARD_COLUMNS, $variantColumns);
        $rows = [$header];
        foreach ($items as $item) {
            $variantMap = [];
            foreach (($item['variants'] ?? []) as $variant) {
                $variantMap[(string) ($variant['variantLabel'] ?? '')] = $variant['price'] ?? '';
            }

            $line = [];
            foreach (self::STANDARD_COLUMNS as $column) {
                if (in_array($column, self::BOOL_COLUMNS, true)) {
                    $line[] = ((bool) ($item[$column] ?? false)) ? 'Yes' : 'No';
                } else {
                    $line[] = $item[$column] ?? '';
                }
            }
            foreach ($variantColumns as $variantColumn) {
                $line[] = $variantMap[$variantColumn] ?? '';
            }
            $rows[] = $line;
        }

        $workbook = [self::SHEET_MAP[$menuType] => $rows];
        $xlsxBytes = SimpleXlsx::writeWorkbook($workbook);

        return [
            'ok' => true,
            'fileName' => 'menu-export-' . $menuType . '-' . date('Ymd-His') . '.xlsx',
            'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'base64' => base64_encode($xlsxBytes),
            'variantColumns' => $variantColumns,
        ];
    }

    public function templateWorkbook(string $menuType): array
    {
        $menuType = $this->normalizeMenuType($menuType);
        if ($menuType === null) {
            return $this->validationError('menuType must be menu_a, menu_b or menu_c.');
        }

        $sample = [
            'source_row' => '1',
            'category' => $menuType === 'menu_b' ? 'Mocktails' : 'Appetizers',
            'item_name' => $menuType === 'menu_b' ? 'Virgin Mojito' : 'Paneer Chilli',
            'description' => 'Sample row. Replace with real menu row.',
            'image_url' => '',
            'is_available' => 'Yes',
            'is_chef_special' => 'No',
            'is_veg' => $menuType === 'menu_b' ? 'No' : 'Yes',
            'is_nonveg' => 'No',
            'is_jain' => 'No',
            'is_universal' => $menuType === 'menu_b' ? 'Yes' : 'No',
            'spice_level' => '',
            'primary_diet' => $menuType === 'menu_b' ? 'bar' : 'veg',
            'pricing_mode' => 'standard',
            'price_veg' => '249',
            'price_jain' => '',
            'price_chicken' => '',
            'price_mutton' => '',
            'price_basa' => '',
            'price_prawns' => '',
            'price_surmai' => '',
            'price_pomfret' => '',
            'price_crab' => '',
            'price_egg' => '',
            'price_half' => '',
            'price_full' => '',
            'price_plain' => '',
            'price_butter' => '',
            'price_medium' => '',
            'price_large' => '',
            'price_direct' => $menuType === 'menu_b' ? '199' : '',
            'category_sort_order' => '0',
            'item_sort_order' => '0',
            'Small' => '149',
            'Large' => '229',
        ];

        $header = array_keys($sample);
        $row = array_values($sample);
        $sheetName = self::SHEET_MAP[$menuType];
        $workbook = [$sheetName => [$header, $row]];
        $xlsxBytes = SimpleXlsx::writeWorkbook($workbook);

        return [
            'ok' => true,
            'fileName' => 'menu-template-' . $menuType . '.xlsx',
            'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'base64' => base64_encode($xlsxBytes),
        ];
    }

    private function normalizeEditorPayload(array $row, string $menuType): array
    {
        $normalized = [
            'id' => (int) ($row['id'] ?? 0),
            'category' => trim((string) ($row['category'] ?? 'Uncategorized')),
            'item_name' => trim((string) ($row['item_name'] ?? $row['itemName'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'image_url' => trim((string) ($row['image_url'] ?? $row['imageUrl'] ?? '')),
            'is_available' => $this->toBool($row['is_available'] ?? $row['isAvailable'] ?? true) ? 1 : 0,
            'is_chef_special' => $this->toBool($row['is_chef_special'] ?? $row['isChefSpecial'] ?? false) ? 1 : 0,
            'is_veg' => $this->toBool($row['is_veg'] ?? $row['isVeg'] ?? false) ? 1 : 0,
            'is_nonveg' => $this->toBool($row['is_nonveg'] ?? $row['isNonveg'] ?? false) ? 1 : 0,
            'is_jain' => $this->toBool($row['is_jain'] ?? $row['isJain'] ?? false) ? 1 : 0,
            'is_universal' => $this->toBool($row['is_universal'] ?? $row['isUniversal'] ?? false) ? 1 : 0,
            'spice_level' => $this->normalizeSpiceLevel($row['spice_level'] ?? $row['spiceLevel'] ?? ''),
            'primary_diet' => trim((string) ($row['primary_diet'] ?? $row['primaryDiet'] ?? '')),
            'pricing_mode' => trim((string) ($row['pricing_mode'] ?? $row['pricingMode'] ?? 'standard')),
            'price_veg' => $this->toNullableDecimal($row['price_veg'] ?? $row['priceVeg'] ?? null),
            'price_jain' => $this->toNullableDecimal($row['price_jain'] ?? $row['priceJain'] ?? null),
            'price_chicken' => $this->toNullableDecimal($row['price_chicken'] ?? $row['priceChicken'] ?? null),
            'price_mutton' => $this->toNullableDecimal($row['price_mutton'] ?? $row['priceMutton'] ?? null),
            'price_basa' => $this->toNullableDecimal($row['price_basa'] ?? $row['priceBasa'] ?? null),
            'price_prawns' => $this->toNullableDecimal($row['price_prawns'] ?? $row['pricePrawns'] ?? null),
            'price_surmai' => $this->toNullableDecimal($row['price_surmai'] ?? $row['priceSurmai'] ?? null),
            'price_pomfret' => $this->toNullableDecimal($row['price_pomfret'] ?? $row['pricePomfret'] ?? null),
            'price_crab' => $this->toNullableDecimal($row['price_crab'] ?? $row['priceCrab'] ?? null),
            'price_egg' => $this->toNullableDecimal($row['price_egg'] ?? $row['priceEgg'] ?? null),
            'price_half' => $this->toNullableDecimal($row['price_half'] ?? $row['priceHalf'] ?? null),
            'price_full' => $this->toNullableDecimal($row['price_full'] ?? $row['priceFull'] ?? null),
            'price_plain' => $this->toNullableDecimal($row['price_plain'] ?? $row['pricePlain'] ?? null),
            'price_butter' => $this->toNullableDecimal($row['price_butter'] ?? $row['priceButter'] ?? null),
            'price_medium' => $this->toNullableDecimal($row['price_medium'] ?? $row['priceMedium'] ?? null),
            'price_large' => $this->toNullableDecimal($row['price_large'] ?? $row['priceLarge'] ?? null),
            'price_direct' => $this->toNullableDecimal($row['price_direct'] ?? $row['priceDirect'] ?? null),
            'category_sort_order' => (int) ($row['category_sort_order'] ?? $row['categorySortOrder'] ?? 0),
            'item_sort_order' => (int) ($row['item_sort_order'] ?? $row['itemSortOrder'] ?? 0),
            'source_row' => $this->toNullableInt($row['source_row'] ?? $row['sourceRow'] ?? null),
            'manually_edited' => 1,
            'variants' => $this->normalizeVariants($row['variants'] ?? []),
        ];

        if ($menuType === 'menu_a' || $menuType === 'menu_c') {
            $normalized = $this->applyMenuAcDietFlags($normalized);
        }

        if ($normalized['pricing_mode'] === '') {
            $normalized['pricing_mode'] = 'standard';
        }

        $normalized['primary_diet'] = $this->classifyPrimaryDiet($normalized, $menuType);
        $normalized['pricing_mode'] = $normalized['variants'] === [] ? 'standard' : 'custom_variants';

        return $normalized;
    }

    private function normalizeImportRow(string $menuType, array $row, array $variantColumns): array
    {
        $normalized = [];
        foreach (self::STANDARD_COLUMNS as $column) {
            $normalized[$column] = $row[$column] ?? null;
        }

        $normalized['category'] = trim((string) ($normalized['category'] ?? 'Uncategorized'));
        $normalized['item_name'] = trim((string) ($normalized['item_name'] ?? ''));
        $normalized['description'] = trim((string) ($normalized['description'] ?? ''));
        $normalized['image_url'] = trim((string) ($normalized['image_url'] ?? ''));
        $normalized['source_row'] = $this->toNullableInt($normalized['source_row'] ?? null);

        foreach (['is_available', 'is_chef_special', 'is_veg', 'is_nonveg', 'is_jain', 'is_universal'] as $flag) {
            $normalized[$flag] = $this->toBool($normalized[$flag] ?? false) ? 1 : 0;
        }

        if ($menuType === 'menu_a' || $menuType === 'menu_c') {
            $normalized = $this->applyMenuAcDietFlags($normalized);
        }

        $normalized['spice_level'] = $this->normalizeSpiceLevel($normalized['spice_level'] ?? '');

        foreach ([
            'price_veg','price_jain','price_chicken','price_mutton','price_basa','price_prawns','price_surmai','price_pomfret',
            'price_crab','price_egg','price_half','price_full','price_plain','price_butter','price_medium','price_large','price_direct',
        ] as $priceColumn) {
            $normalized[$priceColumn] = $this->toNullableDecimal($normalized[$priceColumn] ?? null);
        }

        $normalized['category_sort_order'] = (int) ($normalized['category_sort_order'] ?? 0);
        $normalized['item_sort_order'] = (int) ($normalized['item_sort_order'] ?? 0);
        $normalized['manually_edited'] = 0;

        $variants = [];
        foreach ($variantColumns as $index => $variantColumn) {
            $raw = $row['__unknown__' . $variantColumn] ?? null;
            $price = $this->toNullableDecimal($raw);
            if ($price === null) {
                continue;
            }

            $variants[] = [
                'variantLabel' => $variantColumn,
                'price' => $price,
                'variantSortOrder' => $index,
            ];
        }

        $normalized['variants'] = $variants;
        $normalized['pricing_mode'] = $variants === [] ? 'standard' : 'custom_variants';

        if ($this->isUniversalCategory((string) ($normalized['category'] ?? ''))) {
            $normalized['is_universal'] = 1;
        }

        $normalized['primary_diet'] = $this->classifyPrimaryDiet($normalized, $menuType);
        return $normalized;
    }

    private function normalizeVariants(mixed $variants): array
    {
        if (!is_array($variants)) {
            return [];
        }

        $normalized = [];
        foreach (array_values($variants) as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $label = trim((string) ($variant['variantLabel'] ?? $variant['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $price = $this->toNullableDecimal($variant['price'] ?? null);
            if ($price === null) {
                continue;
            }

            $normalized[] = [
                'variantLabel' => $label,
                'price' => $price,
                'variantSortOrder' => (int) ($variant['variantSortOrder'] ?? $index),
            ];
        }

        return $normalized;
    }

    private function classifyPrimaryDiet(array $row, string $menuType): string
    {
        if ($menuType === 'menu_b') {
            return 'bar';
        }

        if ($menuType === 'menu_a' || $menuType === 'menu_c') {
            $isJain = !empty($row['is_jain']) || (isset($row['price_jain']) && $row['price_jain'] !== null);
            $isVeg = !empty($row['is_veg']) || (isset($row['price_veg']) && $row['price_veg'] !== null);
            $isNonVeg = !empty($row['is_nonveg']) || $this->hasAnyNonVegPrice($row);

            if ($isJain) {
                return 'jain';
            }
            if ($isVeg) {
                return 'veg';
            }
            if ($isNonVeg) {
                return 'nonveg';
            }

            return 'universal';
        }

        $isJain = !empty($row['is_jain']) || (isset($row['price_jain']) && $row['price_jain'] !== null);
        $isVeg = !empty($row['is_veg']) || (isset($row['price_veg']) && $row['price_veg'] !== null);
        $isNonVeg = !empty($row['is_nonveg']) || $this->hasAnyNonVegPrice($row);
        $isUniversal = !empty($row['is_universal']) || $this->isUniversalCategory((string) ($row['category'] ?? ''));

        if ($isUniversal) {
            return 'universal';
        }
        if ($isJain) {
            return 'jain';
        }
        if ($isVeg && $isNonVeg) {
            return 'mixed';
        }
        if ($isNonVeg) {
            return 'nonveg';
        }
        if ($isVeg) {
            return 'veg';
        }

        return '';
    }

    private function applyMenuAcDietFlags(array $row): array
    {
        $hasJain = isset($row['price_jain']) && $row['price_jain'] !== null;
        $hasVeg = isset($row['price_veg']) && $row['price_veg'] !== null;
        $hasNonVeg = $this->hasAnyNonVegPrice($row);

        $row['is_jain'] = ($hasJain || !empty($row['is_jain'])) ? 1 : 0;
        $row['is_veg'] = ($hasVeg || $row['is_jain'] === 1 || !empty($row['is_veg'])) ? 1 : 0;
        $row['is_nonveg'] = ($hasNonVeg || !empty($row['is_nonveg'])) ? 1 : 0;

        $hasAnyDiet = ($row['is_jain'] === 1) || ($row['is_veg'] === 1) || ($row['is_nonveg'] === 1);
        $row['is_universal'] = $hasAnyDiet ? 0 : 1;

        return $row;
    }

    private function hasAnyNonVegPrice(array $row): bool
    {
        $keys = [
            'price_chicken', 'price_mutton', 'price_basa', 'price_prawns',
            'price_surmai', 'price_pomfret', 'price_crab', 'price_egg',
        ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return true;
            }
        }
        return false;
    }

    private function isUniversalCategory(string $category): bool
    {
        $key = strtolower(trim($category));
        return in_array($key, self::UNIVERSAL_CATEGORIES, true);
    }

    private function canonicalColumn(string $header): ?string
    {
        $key = strtolower(trim($header));
        if ($key === '') {
            return null;
        }

        return self::COLUMN_ALIASES[$key] ?? null;
    }

    private function detectVariantColumns(array $header, array $unmappedColumns, array $rows): array
    {
        $variants = [];
        foreach ($unmappedColumns as $column) {
            $index = array_search($column, $header, true);
            if ($index === false) {
                continue;
            }

            $numericSeen = false;
            foreach (array_slice($rows, 1) as $row) {
                $value = trim((string) ($row[(int) $index] ?? ''));
                if ($value === '') {
                    continue;
                }
                if (is_numeric(str_replace(',', '', $value))) {
                    $numericSeen = true;
                    break;
                }
            }

            if ($numericSeen) {
                $variants[] = $column;
            }
        }

        return $variants;
    }

    private function writePreviewStage(array $payload): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/tmp';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create temporary storage path for import preview.');
        }

        $token = 'menu-import-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.json';
        $path = $dir . '/' . $token;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($path, $json) === false) {
            throw new RuntimeException('Unable to persist import preview stage file.');
        }

        return $token;
    }

    private function readPreviewStage(string $tmpPath): array
    {
        $token = basename(trim($tmpPath));
        if ($token === '' || !preg_match('/^menu-import-[a-zA-Z0-9\-]+\.json$/', $token)) {
            throw new RuntimeException('Invalid tmpPath token.');
        }

        $path = dirname(__DIR__, 2) . '/storage/tmp/' . $token;
        if (!is_file($path)) {
            throw new RuntimeException('Import preview has expired or tmpPath is invalid.');
        }

        $raw = file_get_contents($path);
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Unable to read import preview state.');
        }

        return $decoded;
    }

    private function mapItemForApi(array $row): array
    {
        $hasUploadedImage = ((int) ($row['has_uploaded_image'] ?? 0)) === 1;
        $manualImageUrl = trim((string) ($row['image_url'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'category' => (string) ($row['category'] ?? ''),
            'itemName' => (string) ($row['item_name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'imageUrl' => $manualImageUrl,
            'hasUploadedImage' => $hasUploadedImage,
            'effectiveImageSource' => $hasUploadedImage ? 'uploaded' : ($manualImageUrl !== '' ? 'url' : ''),
            'isAvailable' => ((int) ($row['is_available'] ?? 1)) === 1,
            'isChefSpecial' => ((int) ($row['is_chef_special'] ?? 0)) === 1,
            'isVeg' => ((int) ($row['is_veg'] ?? 0)) === 1,
            'isNonveg' => ((int) ($row['is_nonveg'] ?? 0)) === 1,
            'isJain' => ((int) ($row['is_jain'] ?? 0)) === 1,
            'isUniversal' => ((int) ($row['is_universal'] ?? 0)) === 1,
            'spiceLevel' => $this->normalizeSpiceLevel($row['spice_level'] ?? ''),
            'primaryDiet' => (string) ($row['primary_diet'] ?? ''),
            'pricingMode' => (string) ($row['pricing_mode'] ?? 'standard'),
            'priceVeg' => $this->toNullableDecimal($row['price_veg'] ?? null),
            'priceJain' => $this->toNullableDecimal($row['price_jain'] ?? null),
            'priceChicken' => $this->toNullableDecimal($row['price_chicken'] ?? null),
            'priceMutton' => $this->toNullableDecimal($row['price_mutton'] ?? null),
            'priceBasa' => $this->toNullableDecimal($row['price_basa'] ?? null),
            'pricePrawns' => $this->toNullableDecimal($row['price_prawns'] ?? null),
            'priceSurmai' => $this->toNullableDecimal($row['price_surmai'] ?? null),
            'pricePomfret' => $this->toNullableDecimal($row['price_pomfret'] ?? null),
            'priceCrab' => $this->toNullableDecimal($row['price_crab'] ?? null),
            'priceEgg' => $this->toNullableDecimal($row['price_egg'] ?? null),
            'priceHalf' => $this->toNullableDecimal($row['price_half'] ?? null),
            'priceFull' => $this->toNullableDecimal($row['price_full'] ?? null),
            'pricePlain' => $this->toNullableDecimal($row['price_plain'] ?? null),
            'priceButter' => $this->toNullableDecimal($row['price_butter'] ?? null),
            'priceMedium' => $this->toNullableDecimal($row['price_medium'] ?? null),
            'priceLarge' => $this->toNullableDecimal($row['price_large'] ?? null),
            'priceDirect' => $this->toNullableDecimal($row['price_direct'] ?? null),
            'categorySortOrder' => (int) ($row['category_sort_order'] ?? 0),
            'itemSortOrder' => (int) ($row['item_sort_order'] ?? 0),
            'sourceRow' => $this->toNullableInt($row['source_row'] ?? null),
            'manuallyEdited' => ((int) ($row['manually_edited'] ?? 0)) === 1,
            'variants' => is_array($row['variants'] ?? null) ? $row['variants'] : [],
        ];
    }

    private function mapCategoryForApi(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'sortOrder' => (int) ($row['sort_order'] ?? 0),
            'isActive' => ((int) ($row['is_active'] ?? 1)) === 1,
            'aliases' => $this->safeJsonArray($row['aliases_json'] ?? '[]'),
        ];
    }

    private function seedFromLegacyIfEmpty(string $menuType, MenuManagementRepository $repository): void
    {
        $existing = $repository->listMenuItems($menuType);
        if ($existing !== []) {
            return;
        }

        $sourceKeyMap = [
            'menu_a' => 'awg_main',
            'menu_b' => 'cocktail',
            'menu_c' => 'namastemenu',
        ];

        $sourceKey = $sourceKeyMap[$menuType] ?? null;
        if ($sourceKey === null) {
            return;
        }

        $legacyRepo = new MenuRepository(Database::connection());
        $source = $legacyRepo->findSourceByKey($sourceKey);
        if (!is_array($source) && $sourceKey === 'namastemenu') {
            $source = $legacyRepo->findSourceByKey('namaste_chef');
        }
        if (!is_array($source)) {
            return;
        }

        $sourceId = (int) ($source['id'] ?? 0);
        if ($sourceId <= 0) {
            return;
        }

        $legacyRows = $legacyRepo->listAdminSnapshotRowsBySource($sourceId);
        if ($legacyRows === []) {
            return;
        }

        $categoryCounters = [];

        $repository->begin();
        try {
            foreach ($legacyRows as $legacyRow) {
                if (!is_array($legacyRow)) {
                    continue;
                }

                $category = trim((string) ($legacyRow['categoryName'] ?? 'Uncategorized'));
                if ($category === '') {
                    $category = 'Uncategorized';
                }

                if (!array_key_exists($category, $categoryCounters)) {
                    $categoryCounters[$category] = 0;
                }

                $variants = [];
                foreach (['proteins', 'servings', 'dynamic', 'prices'] as $variantGroup) {
                    $groupValues = $legacyRow[$variantGroup] ?? [];
                    if (!is_array($groupValues)) {
                        continue;
                    }

                    foreach ($groupValues as $label => $price) {
                        $priceValue = $this->toNullableDecimal($price);
                        if ($priceValue === null) {
                            continue;
                        }

                        $variants[] = [
                            'variantLabel' => trim((string) $label),
                            'price' => $priceValue,
                        ];
                    }
                }

                $payload = $this->normalizeEditorPayload([
                    'category' => $category,
                    'itemName' => (string) ($legacyRow['itemName'] ?? ''),
                    'description' => (string) ($legacyRow['description'] ?? ''),
                    'imageUrl' => (string) ($legacyRow['imageUrl'] ?? ''),
                    'isAvailable' => (bool) ($legacyRow['isAvailable'] ?? true),
                    'isChefSpecial' => (bool) ($legacyRow['chefSpecial'] ?? false),
                    'isVeg' => (bool) ($legacyRow['isVeg'] ?? false),
                    'isNonveg' => !((bool) ($legacyRow['isVeg'] ?? false)),
                    'isJain' => !empty($legacyRow['jainPrice']),
                    'isUniversal' => $menuType === 'menu_b',
                    'priceJain' => $legacyRow['jainPrice'] ?? null,
                    'priceDirect' => $menuType === 'menu_b' ? $this->firstLegacyPrice($legacyRow) : null,
                    'sourceRow' => $legacyRow['rowIndex'] ?? null,
                    'itemSortOrder' => $categoryCounters[$category],
                    'variants' => $variants,
                ], $menuType);

                $categoryRow = $repository->ensureCategory($menuType, $category);
                $payload['category_sort_order'] = (int) ($categoryRow['sort_order'] ?? 0);
                $payload['item_sort_order'] = $categoryCounters[$category];
                $payload['manually_edited'] = 0;

                $itemId = $repository->insertItem($menuType, $payload);
                $repository->replaceVariantsForItem($itemId, $payload['variants'] ?? []);

                $categoryCounters[$category]++;
            }

            $repository->commit();
        } catch (Throwable $exception) {
            $repository->rollBack();
        }
    }

    private function firstLegacyPrice(array $legacyRow): ?float
    {
        foreach (['prices', 'proteins', 'servings', 'dynamic'] as $variantGroup) {
            $groupValues = $legacyRow[$variantGroup] ?? [];
            if (!is_array($groupValues)) {
                continue;
            }

            foreach ($groupValues as $price) {
                $priceValue = $this->toNullableDecimal($price);
                if ($priceValue !== null) {
                    return $priceValue;
                }
            }
        }

        return null;
    }

    private function normalizeMenuType(string $menuType): ?string
    {
        $menuType = trim(strtolower($menuType));
        if (!in_array($menuType, self::MENU_TYPES, true)) {
            return null;
        }
        return $menuType;
    }

    private function validationError(string $message): array
    {
        return [
            'ok' => false,
            'error' => 'VALIDATION_ERROR',
            'message' => $message,
        ];
    }

    private function safeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function convertUploadedImageToWebp(string $tmpPath): ?string
    {
        $imageInfo = @getimagesize($tmpPath);
        if (!is_array($imageInfo)) {
            return null;
        }

        $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
        $source = null;

        if ($mime === 'image/jpeg') {
            $source = @imagecreatefromjpeg($tmpPath);
        } elseif ($mime === 'image/png') {
            $source = @imagecreatefrompng($tmpPath);
        } elseif ($mime === 'image/gif') {
            $source = @imagecreatefromgif($tmpPath);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $source = @imagecreatefromwebp($tmpPath);
        }

        if (!is_resource($source) && !($source instanceof \GdImage)) {
            return null;
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($source);
        }
        @imagealphablending($source, true);
        @imagesavealpha($source, true);

        ob_start();
        $encoded = @imagewebp($source, null, 82);
        $webpBinary = ob_get_clean();
        @imagedestroy($source);

        if ($encoded !== true || !is_string($webpBinary) || $webpBinary === '') {
            return null;
        }

        return $webpBinary;
    }

    private function buildUploadedImageDataUri(?array $preview): ?string
    {
        if (!is_array($preview)) {
            return null;
        }

        $binary = $preview['binary'] ?? null;
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        $mime = trim((string) ($preview['mime'] ?? 'image/webp'));
        if ($mime === '') {
            $mime = 'image/webp';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function toNullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', trim($value));
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeSpiceLevel(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $text = strtolower($raw);

        if (in_array($text, ['none', 'non spicy', 'non-spicy', 'no spice', 'no spicy', 'not spicy', 'n/a'], true)) {
            return '';
        }

        if (in_array($text, ['mild', 'mild spicy', 'mild-spicy', 'low'], true)) {
            return 'Mild';
        }

        if (in_array($text, ['medium', 'medium spicy', 'medium-spicy'], true)) {
            return 'Medium';
        }

        if (in_array($text, ['extra hot', 'extra-hot', 'very spicy'], true)) {
            return 'Extra Hot';
        }

        if (in_array($text, ['hot', 'spicy', 'hot spicy', 'hot-spicy'], true)) {
            return 'Hot';
        }

        return '';
    }
}
