<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Database;
use AWG\Repositories\MenuRepository;

final class MenuService
{
    public function getFoodMenuBySourceKey(string $sourceKey): array
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Source key is required.',
            ];
        }

        $repository = new MenuRepository(Database::connection());
        $source = $this->findSourceWithAliases($repository, $sourceKey);

        if (!is_array($source)) {
            return [
                'ok' => false,
                'error' => 'SOURCE_NOT_FOUND',
                'message' => 'Menu source was not found.',
                'sourceKey' => $sourceKey,
            ];
        }

        $items = $repository->listFoodMenuItemsBySource((int) $source['id']);
        $latestRun = $repository->latestImportSummaryBySource((int) $source['id']);

        return [
            'ok' => true,
            'result' => 'success',
            'sourceKey' => (string) ($source['source_key'] ?? $sourceKey),
            'requestedSourceKey' => $sourceKey,
            'sourceName' => (string) $source['source_name'],
            'items' => $items,
            'itemCount' => count($items),
            'lastImport' => $this->mapImportSummary($latestRun),
        ];
    }

    public function getCocktailMenu(): array
    {
        $repository = new MenuRepository(Database::connection());
        $source = $repository->findSourceByKey('cocktail');

        if (!is_array($source)) {
            return [
                'ok' => false,
                'error' => 'SOURCE_NOT_FOUND',
                'message' => 'Cocktail source was not found.',
            ];
        }

        $items = $repository->listCocktailMenuItemsBySource((int) $source['id']);
        $latestRun = $repository->latestImportSummaryBySource((int) $source['id']);

        return [
            'ok' => true,
            'result' => 'success',
            'sourceKey' => 'cocktail',
            'data' => $items,
            'itemCount' => count($items),
            'lastImport' => $this->mapImportSummary($latestRun),
        ];
    }

    public function importMenus(?string $sourceKey = null): array
    {
        $importService = new MenuImportService();
        if ($sourceKey !== null && trim($sourceKey) !== '') {
            return $importService->importSourceByKey($this->normalizeSourceKeyAlias(trim($sourceKey)));
        }

        return $importService->importAll();
    }

    public function listMenuSources(): array
    {
        $repository = new MenuRepository(Database::connection());
        $sources = $repository->listActiveSources();
        $mapped = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $latestRun = $repository->latestImportSummaryBySource((int) $source['id']);
            $mapped[] = [
                'sourceKey' => (string) $source['source_key'],
                'sourceName' => (string) $source['source_name'],
                'sourceType' => (string) $source['source_type'],
                'sourceUrl' => (string) $source['source_url'],
                'lastImport' => $this->mapImportSummary($latestRun),
            ];
        }

        return [
            'ok' => true,
            'result' => 'success',
            'sources' => $mapped,
            'sourceCount' => count($mapped),
        ];
    }

    public function getAdminWorkspaceOverview(): array
    {
        $repository = new MenuRepository(Database::connection());
        $sources = $repository->listActiveSources();
        $rows = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $sourceId = (int) ($source['id'] ?? 0);
            $sourceKey = (string) ($source['source_key'] ?? '');
            $isCocktail = $sourceKey === 'cocktail' || (string) ($source['source_type'] ?? '') === 'cocktail';
            $items = $isCocktail
                ? $repository->listCocktailMenuItemsBySource($sourceId)
                : $repository->listFoodMenuItemsBySource($sourceId);

            $rows[] = [
                'sourceKey' => $sourceKey,
                'sourceName' => (string) ($source['source_name'] ?? ''),
                'sourceType' => (string) ($source['source_type'] ?? ''),
                'itemCount' => count($items),
                'lastImport' => $this->mapImportSummary($repository->latestImportSummaryBySource($sourceId)),
            ];
        }

        return [
            'ok' => true,
            'result' => 'success',
            'sources' => $rows,
            'sourceCount' => count($rows),
        ];
    }

    public function getAdminSourceSnapshot(string $sourceKey): array
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Source key is required.',
            ];
        }

        $repository = new MenuRepository(Database::connection());
        $source = $this->findSourceWithAliases($repository, $sourceKey);
        if (!is_array($source)) {
            return [
                'ok' => false,
                'error' => 'SOURCE_NOT_FOUND',
                'message' => 'Menu source was not found.',
                'sourceKey' => $sourceKey,
            ];
        }

        $sourceId = (int) ($source['id'] ?? 0);
        $items = $repository->listAdminSnapshotRowsBySource($sourceId);

        $categorySet = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $categoryName = trim((string) ($item['categoryName'] ?? ''));
            if ($categoryName !== '') {
                $categorySet[$categoryName] = true;
            }
        }

        return [
            'ok' => true,
            'result' => 'success',
            'source' => [
                'sourceKey' => (string) ($source['source_key'] ?? ''),
                'sourceName' => (string) ($source['source_name'] ?? ''),
                'sourceType' => (string) ($source['source_type'] ?? ''),
            ],
            'items' => $items,
            'categories' => array_values(array_keys($categorySet)),
            'itemCount' => count($items),
            'lastImport' => $this->mapImportSummary($repository->latestImportSummaryBySource($sourceId)),
        ];
    }

    public function saveAdminSourceSnapshot(string $sourceKey, mixed $items): array
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Source key is required.',
            ];
        }

        if (!is_array($items)) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Items payload must be an array.',
            ];
        }

        $repository = new MenuRepository(Database::connection());
        $source = $this->findSourceWithAliases($repository, $sourceKey);
        if (!is_array($source)) {
            return [
                'ok' => false,
                'error' => 'SOURCE_NOT_FOUND',
                'message' => 'Menu source was not found.',
                'sourceKey' => $sourceKey,
            ];
        }

        $normalized = [];
        foreach (array_values($items) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['itemName'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $category = trim((string) ($row['categoryName'] ?? $row['cat'] ?? ''));
            if ($category === '') {
                $category = 'Uncategorized';
            }

            $proteins = $this->toStringMap($row['proteins'] ?? []);
            $servings = $this->toStringMap($row['servings'] ?? []);
            $dynamic = $this->toStringMap($row['dynamic'] ?? []);
            $prices = $this->toStringMap($row['prices'] ?? []);

            $isVeg = $this->toBool($row['isVeg'] ?? true);
            $rowDiet = trim((string) ($row['rowDiet'] ?? ''));
            if ($rowDiet === '') {
                $rowDiet = $isVeg ? 'veg' : 'nonveg';
            }

            $normalized[] = [
                'category' => $category,
                'name' => $name,
                'description' => (string) ($row['description'] ?? $row['desc'] ?? ''),
                'imageUrl' => (string) ($row['imageUrl'] ?? $row['img'] ?? ''),
                'servingUnit' => $this->nullableString($row['servingUnit'] ?? null),
                'chefSpecial' => $this->toBool($row['chefSpecial'] ?? $row['chef'] ?? false),
                'spiceLevel' => $this->nullableString($row['spiceLevel'] ?? $row['spice'] ?? null),
                'jainPrice' => $this->nullableString($row['jainPrice'] ?? null),
                'isVeg' => $isVeg,
                'rowDiet' => $rowDiet,
                'descNonVeg' => $this->toBool($row['descNonVeg'] ?? false),
                'isAvailable' => $this->toBool($row['isAvailable'] ?? $row['avail'] ?? true),
                'rowIndex' => (int) ($row['rowIndex'] ?? $row['id'] ?? $index + 1),
                'proteins' => $proteins,
                'servings' => $servings,
                'dynamic' => $dynamic,
                'prices' => $prices,
                'raw' => [
                    'updatedBy' => 'admin-menu-editor',
                    'sourceKey' => $sourceKey,
                    'savedAt' => date('c'),
                ],
            ];
        }

        $persisted = $repository->replaceSourceItems((int) $source['id'], $normalized);

        return [
            'ok' => true,
            'result' => 'snapshot_saved',
            'sourceKey' => $sourceKey,
            'itemCount' => (int) ($persisted['items'] ?? 0),
            'variantCount' => (int) ($persisted['variants'] ?? 0),
        ];
    }

    public function saveAdminCategoryOrder(string $sourceKey, mixed $categories): array
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Source key is required.',
            ];
        }

        if (!is_array($categories)) {
            return [
                'ok' => false,
                'error' => 'VALIDATION_ERROR',
                'message' => 'Categories payload must be an array.',
            ];
        }

        $repository = new MenuRepository(Database::connection());
        $source = $this->findSourceWithAliases($repository, $sourceKey);
        if (!is_array($source)) {
            return [
                'ok' => false,
                'error' => 'SOURCE_NOT_FOUND',
                'message' => 'Menu source was not found.',
                'sourceKey' => $sourceKey,
            ];
        }

        $updated = $repository->applyCategoryOrder((int) $source['id'], $categories);

        return [
            'ok' => true,
            'result' => 'category_order_saved',
            'sourceKey' => $sourceKey,
            'updatedRows' => $updated,
        ];
    }

    public function exportSourceMenu(string $sourceKey): array
    {
        $snapshot = $this->getAdminSourceSnapshot($sourceKey);
        if (($snapshot['ok'] ?? false) !== true) {
            return $snapshot;
        }

        return [
            'ok' => true,
            'result' => 'success',
            'source' => $snapshot['source'] ?? null,
            'rows' => $snapshot['items'] ?? [],
            'count' => (int) ($snapshot['itemCount'] ?? 0),
            'exportedAt' => date('c'),
        ];
    }

    private function seedDemoMenuIfEmpty(MenuRepository $repository, string $sourceKey): void
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return;
        }

        $source = $repository->findSourceByKey($sourceKey);
        if (!is_array($source)) {
            return;
        }

        $sourceId = (int) ($source['id'] ?? 0);
        if ($sourceId <= 0) {
            return;
        }

        $isCocktail = $sourceKey === 'cocktail' || (string) ($source['source_type'] ?? '') === 'cocktail';
        $existing = $isCocktail
            ? $repository->listCocktailMenuItemsBySource($sourceId)
            : $repository->listFoodMenuItemsBySource($sourceId);

        if ($existing !== []) {
            return;
        }

        $repository->replaceSourceItems($sourceId, $isCocktail ? $this->demoCocktailItems() : $this->demoFoodItems($sourceKey));
    }

    private function demoFoodItems(string $sourceKey): array
    {
        $sourceLabel = in_array($sourceKey, ['namaste_chef', 'namastemenu'], true) ? 'Namaste Menu' : 'Asian Wok';

        return [
            [
                'category' => 'Chef Specials',
                'name' => $sourceLabel . ' Signature Dumplings',
                'description' => 'Hand-folded dumplings with soy glaze and chili oil.',
                'imageUrl' => '',
                'servingUnit' => 'Plate',
                'chefSpecial' => true,
                'spiceLevel' => 'Medium',
                'jainPrice' => '299',
                'isVeg' => true,
                'rowDiet' => 'veg',
                'descNonVeg' => false,
                'isAvailable' => true,
                'rowIndex' => 1,
                'proteins' => ['veg' => 'Vegetable'],
                'servings' => ['plate' => 'Plate'],
                'dynamic' => ['popular' => 'Best Seller'],
                'prices' => [],
                'raw' => ['demo' => true, 'sourceKey' => $sourceKey],
            ],
            [
                'category' => 'Wok House',
                'name' => $sourceLabel . ' Chilli Garlic Noodles',
                'description' => 'Fresh noodles, wok sauce, and crunchy vegetables.',
                'imageUrl' => '',
                'servingUnit' => 'Bowl',
                'chefSpecial' => false,
                'spiceLevel' => 'Hot',
                'jainPrice' => '249',
                'isVeg' => true,
                'rowDiet' => 'veg',
                'descNonVeg' => false,
                'isAvailable' => true,
                'rowIndex' => 2,
                'proteins' => ['veg' => 'Veg'],
                'servings' => ['bowl' => 'Bowl'],
                'dynamic' => ['new' => 'New'],
                'prices' => [],
                'raw' => ['demo' => true, 'sourceKey' => $sourceKey],
            ],
        ];
    }

    private function demoCocktailItems(): array
    {
        return [
            [
                'category' => 'House Signatures',
                'name' => 'Citrus Mule',
                'description' => 'Ginger, lime, and fresh basil with a crisp finish.',
                'imageUrl' => '',
                'servingUnit' => null,
                'chefSpecial' => false,
                'spiceLevel' => 'Low',
                'jainPrice' => null,
                'isVeg' => true,
                'rowDiet' => 'veg',
                'descNonVeg' => false,
                'isAvailable' => true,
                'rowIndex' => 1,
                'proteins' => [],
                'servings' => [],
                'dynamic' => [],
                'prices' => ['single' => '249', 'double' => '399'],
                'raw' => ['demo' => true, 'sourceKey' => 'cocktail'],
            ],
            [
                'category' => 'Mocktails',
                'name' => 'Berry Garden Sparkler',
                'description' => 'Berry blend with citrus soda and mint.',
                'imageUrl' => '',
                'servingUnit' => null,
                'chefSpecial' => true,
                'spiceLevel' => 'Low',
                'jainPrice' => null,
                'isVeg' => true,
                'rowDiet' => 'veg',
                'descNonVeg' => false,
                'isAvailable' => true,
                'rowIndex' => 2,
                'proteins' => [],
                'servings' => [],
                'dynamic' => [],
                'prices' => ['single' => '199', 'double' => '329'],
                'raw' => ['demo' => true, 'sourceKey' => 'cocktail'],
            ],
        ];
    }

    private function mapImportSummary(?array $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        return [
            'runId' => (int) ($row['id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'startedAt' => (string) ($row['started_at'] ?? ''),
            'completedAt' => $row['completed_at'] ?? null,
            'itemCount' => (int) ($row['imported_item_count'] ?? 0),
            'variantCount' => (int) ($row['imported_variant_count'] ?? 0),
            'errorMessage' => $row['error_message'] ?? null,
        ];
    }

    private function toStringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            $result[$normalizedKey] = trim((string) $item);
        }

        return $result;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'on'], true);
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function normalizeSourceKeyAlias(string $sourceKey): string
    {
        return $sourceKey === 'namaste_chef' ? 'namastemenu' : $sourceKey;
    }

    private function sourceKeyCandidates(string $sourceKey): array
    {
        $normalized = $this->normalizeSourceKeyAlias($sourceKey);
        if ($normalized === 'namastemenu') {
            return ['namastemenu', 'namaste_chef'];
        }

        return [$sourceKey];
    }

    private function findSourceWithAliases(MenuRepository $repository, string $sourceKey): ?array
    {
        foreach ($this->sourceKeyCandidates($sourceKey) as $candidate) {
            $source = $repository->findSourceByKey($candidate);
            if (is_array($source)) {
                return $source;
            }
        }

        return null;
    }
}