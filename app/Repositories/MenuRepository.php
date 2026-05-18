<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;
use Throwable;

final class MenuRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function listActiveSources(): array
    {
        $statement = $this->connection->query(
            'SELECT * FROM menu_sources WHERE is_active = 1 ORDER BY id ASC'
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function findSourceByKey(string $sourceKey): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM menu_sources WHERE source_key = :source_key LIMIT 1'
        );
        $statement->execute(['source_key' => $sourceKey]);

        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function startImportRun(int $menuSourceId, int $payloadSize, string $payloadHash): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO menu_import_runs (menu_source_id, source_payload_size, source_payload_hash, status) VALUES (:menu_source_id, :source_payload_size, :source_payload_hash, :status)'
        );

        $statement->execute([
            'menu_source_id' => $menuSourceId,
            'source_payload_size' => $payloadSize,
            'source_payload_hash' => $payloadHash,
            'status' => 'running',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function markImportRunSuccess(int $runId, int $itemCount, int $variantCount, array $summary): void
    {
        $statement = $this->connection->prepare(
            'UPDATE menu_import_runs SET status = :status, completed_at = NOW(), imported_item_count = :imported_item_count, imported_variant_count = :imported_variant_count, summary_json = :summary_json, error_message = NULL WHERE id = :id'
        );

        $statement->execute([
            'id' => $runId,
            'status' => 'success',
            'imported_item_count' => $itemCount,
            'imported_variant_count' => $variantCount,
            'summary_json' => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function markImportRunFailed(int $runId, string $error): void
    {
        $statement = $this->connection->prepare(
            'UPDATE menu_import_runs SET status = :status, completed_at = NOW(), error_message = :error_message WHERE id = :id'
        );

        $statement->execute([
            'id' => $runId,
            'status' => 'failed',
            'error_message' => mb_substr($error, 0, 500),
        ]);
    }

    public function replaceSourceItems(int $menuSourceId, array $items): array
    {
        $insertedItems = 0;
        $insertedVariants = 0;

        $this->connection->beginTransaction();

        try {
            $deleteVariants = $this->connection->prepare(
                'DELETE v FROM menu_item_variants v INNER JOIN menu_items i ON i.id = v.menu_item_id WHERE i.menu_source_id = :menu_source_id'
            );
            $deleteVariants->execute(['menu_source_id' => $menuSourceId]);

            $deleteItems = $this->connection->prepare(
                'DELETE FROM menu_items WHERE menu_source_id = :menu_source_id'
            );
            $deleteItems->execute(['menu_source_id' => $menuSourceId]);

            $insertItem = $this->connection->prepare(
                'INSERT INTO menu_items (
                    menu_source_id,
                    category_name,
                    item_name,
                    item_description,
                    image_url,
                    serving_unit,
                    chef_special,
                    spice_level,
                    jain_price,
                    is_veg,
                    row_diet,
                    desc_non_veg,
                    is_available,
                    row_index,
                    raw_row_json
                ) VALUES (
                    :menu_source_id,
                    :category_name,
                    :item_name,
                    :item_description,
                    :image_url,
                    :serving_unit,
                    :chef_special,
                    :spice_level,
                    :jain_price,
                    :is_veg,
                    :row_diet,
                    :desc_non_veg,
                    :is_available,
                    :row_index,
                    :raw_row_json
                )'
            );

            $insertVariant = $this->connection->prepare(
                'INSERT INTO menu_item_variants (menu_item_id, variant_group, variant_key, variant_value, variant_order) VALUES (:menu_item_id, :variant_group, :variant_key, :variant_value, :variant_order)'
            );

            foreach ($items as $itemIndex => $item) {
                $insertItem->execute([
                    'menu_source_id' => $menuSourceId,
                    'category_name' => (string) ($item['category'] ?? 'Other'),
                    'item_name' => (string) ($item['name'] ?? ''),
                    'item_description' => (string) ($item['description'] ?? ''),
                    'image_url' => (string) ($item['imageUrl'] ?? ''),
                    'serving_unit' => $item['servingUnit'] ?? null,
                    'chef_special' => !empty($item['chefSpecial']) ? 1 : 0,
                    'spice_level' => $item['spiceLevel'] ?? null,
                    'jain_price' => $item['jainPrice'] ?? null,
                    'is_veg' => !empty($item['isVeg']) ? 1 : 0,
                    'row_diet' => (string) ($item['rowDiet'] ?? 'veg'),
                    'desc_non_veg' => !empty($item['descNonVeg']) ? 1 : 0,
                    'is_available' => array_key_exists('isAvailable', $item)
                        ? (!empty($item['isAvailable']) ? 1 : 0)
                        : 1,
                    'row_index' => (int) ($item['rowIndex'] ?? $itemIndex),
                    'raw_row_json' => json_encode($item['raw'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);

                $insertedItems++;
                $menuItemId = (int) $this->connection->lastInsertId();

                foreach (($item['proteins'] ?? []) as $variantKey => $variantValue) {
                    $insertVariant->execute([
                        'menu_item_id' => $menuItemId,
                        'variant_group' => 'protein',
                        'variant_key' => (string) $variantKey,
                        'variant_value' => (string) $variantValue,
                        'variant_order' => $insertedVariants,
                    ]);
                    $insertedVariants++;
                }

                foreach (($item['servings'] ?? []) as $variantKey => $variantValue) {
                    $insertVariant->execute([
                        'menu_item_id' => $menuItemId,
                        'variant_group' => 'serving',
                        'variant_key' => (string) $variantKey,
                        'variant_value' => (string) $variantValue,
                        'variant_order' => $insertedVariants,
                    ]);
                    $insertedVariants++;
                }

                foreach (($item['dynamic'] ?? []) as $variantKey => $variantValue) {
                    $insertVariant->execute([
                        'menu_item_id' => $menuItemId,
                        'variant_group' => 'dynamic',
                        'variant_key' => (string) $variantKey,
                        'variant_value' => (string) $variantValue,
                        'variant_order' => $insertedVariants,
                    ]);
                    $insertedVariants++;
                }

                foreach (($item['prices'] ?? []) as $variantKey => $variantValue) {
                    $insertVariant->execute([
                        'menu_item_id' => $menuItemId,
                        'variant_group' => 'price_unit',
                        'variant_key' => (string) $variantKey,
                        'variant_value' => (string) $variantValue,
                        'variant_order' => $insertedVariants,
                    ]);
                    $insertedVariants++;
                }
            }

            if ($this->connection->inTransaction()) {
                $this->connection->commit();
            }

            return [
                'items' => $insertedItems,
                'variants' => $insertedVariants,
            ];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function listFoodMenuItemsBySource(int $menuSourceId): array
    {
        $itemStatement = $this->connection->prepare(
            'SELECT * FROM menu_items WHERE menu_source_id = :menu_source_id ORDER BY row_index ASC, id ASC'
        );
        $itemStatement->execute(['menu_source_id' => $menuSourceId]);
        $items = $itemStatement->fetchAll();
        if (!is_array($items) || $items === []) {
            return [];
        }

        $itemIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($items, static fn ($row): bool => is_array($row))
        );

        if ($itemIds === []) {
            return [];
        }

        $variantPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
        $variantStatement = $this->connection->prepare(
            "SELECT * FROM menu_item_variants WHERE menu_item_id IN ({$variantPlaceholders}) ORDER BY variant_order ASC, id ASC"
        );
        $variantStatement->execute($itemIds);
        $variantRows = $variantStatement->fetchAll();
        $variantsByItem = [];

        foreach (($variantRows ?: []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $itemId = (int) $variant['menu_item_id'];
            $variantsByItem[$itemId][] = $variant;
        }

        return array_map(function (array $row) use ($variantsByItem): array {
            $itemId = (int) $row['id'];
            $variantRows = $variantsByItem[$itemId] ?? [];

            $proteins = [];
            $servings = [];
            $dynamic = [];

            foreach ($variantRows as $variant) {
                $key = (string) ($variant['variant_key'] ?? '');
                $value = (string) ($variant['variant_value'] ?? '');
                $group = (string) ($variant['variant_group'] ?? '');

                if ($group === 'protein') {
                    $proteins[$key] = $value;
                } elseif ($group === 'serving') {
                    $servings[$key] = $value;
                } elseif ($group === 'dynamic') {
                    $dynamic[$key] = $value;
                }
            }

            return [
                'id' => (int) $row['row_index'],
                'cat' => (string) $row['category_name'],
                'name' => (string) $row['item_name'],
                'desc' => (string) ($row['item_description'] ?? ''),
                'proteins' => $proteins,
                'servings' => $servings,
                'dynamic' => $dynamic,
                'servingUnit' => $row['serving_unit'] !== null ? (string) $row['serving_unit'] : null,
                'chef' => ((int) $row['chef_special']) === 1,
                'spice' => (string) ($row['spice_level'] ?? ''),
                'jainPrice' => $row['jain_price'] !== null ? (string) $row['jain_price'] : null,
                'img' => (string) ($row['image_url'] ?? ''),
                'isVeg' => ((int) $row['is_veg']) === 1,
                'rowDiet' => (string) $row['row_diet'],
                'descNonVeg' => ((int) $row['desc_non_veg']) === 1,
            ];
        }, $items);
    }

    public function listCocktailMenuItemsBySource(int $menuSourceId): array
    {
        $itemStatement = $this->connection->prepare(
            'SELECT * FROM menu_items WHERE menu_source_id = :menu_source_id AND is_available = 1 ORDER BY row_index ASC, id ASC'
        );
        $itemStatement->execute(['menu_source_id' => $menuSourceId]);
        $items = $itemStatement->fetchAll();
        if (!is_array($items) || $items === []) {
            return [];
        }

        $itemIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($items, static fn ($row): bool => is_array($row))
        );
        if ($itemIds === []) {
            return [];
        }

        $variantPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
        $variantStatement = $this->connection->prepare(
            "SELECT * FROM menu_item_variants WHERE menu_item_id IN ({$variantPlaceholders}) AND variant_group = 'price_unit' ORDER BY variant_order ASC, id ASC"
        );
        $variantStatement->execute($itemIds);
        $variantRows = $variantStatement->fetchAll();
        $pricesByItem = [];

        foreach (($variantRows ?: []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $itemId = (int) $variant['menu_item_id'];
            $pricesByItem[$itemId][(string) $variant['variant_key']] = (string) ($variant['variant_value'] ?? '');
        }

        return array_map(function (array $row) use ($pricesByItem): array {
            $itemId = (int) $row['id'];
            return [
                'name' => (string) $row['item_name'],
                'cat' => (string) $row['category_name'],
                'desc' => (string) ($row['item_description'] ?? ''),
                'prices' => $pricesByItem[$itemId] ?? [],
                'avail' => ((int) $row['is_available']) === 1,
            ];
        }, $items);
    }

    public function listAdminSnapshotRowsBySource(int $menuSourceId): array
    {
        $itemStatement = $this->connection->prepare(
            'SELECT * FROM menu_items WHERE menu_source_id = :menu_source_id ORDER BY row_index ASC, id ASC'
        );
        $itemStatement->execute(['menu_source_id' => $menuSourceId]);
        $items = $itemStatement->fetchAll();
        if (!is_array($items) || $items === []) {
            return [];
        }

        $itemIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($items, static fn ($row): bool => is_array($row))
        );

        if ($itemIds === []) {
            return [];
        }

        $variantPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
        $variantStatement = $this->connection->prepare(
            "SELECT * FROM menu_item_variants WHERE menu_item_id IN ({$variantPlaceholders}) ORDER BY variant_order ASC, id ASC"
        );
        $variantStatement->execute($itemIds);
        $variantRows = $variantStatement->fetchAll();
        $variantsByItem = [];

        foreach (($variantRows ?: []) as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $itemId = (int) $variant['menu_item_id'];
            $variantsByItem[$itemId][] = $variant;
        }

        return array_map(function (array $row) use ($variantsByItem): array {
            $itemId = (int) $row['id'];
            $variantRows = $variantsByItem[$itemId] ?? [];

            $proteins = [];
            $servings = [];
            $dynamic = [];
            $prices = [];

            foreach ($variantRows as $variant) {
                $key = (string) ($variant['variant_key'] ?? '');
                $value = (string) ($variant['variant_value'] ?? '');
                $group = (string) ($variant['variant_group'] ?? '');

                if ($group === 'protein') {
                    $proteins[$key] = $value;
                } elseif ($group === 'serving') {
                    $servings[$key] = $value;
                } elseif ($group === 'dynamic') {
                    $dynamic[$key] = $value;
                } elseif ($group === 'price_unit') {
                    $prices[$key] = $value;
                }
            }

            return [
                'menuItemId' => $itemId,
                'rowIndex' => (int) $row['row_index'],
                'categoryName' => (string) $row['category_name'],
                'itemName' => (string) $row['item_name'],
                'description' => (string) ($row['item_description'] ?? ''),
                'imageUrl' => (string) ($row['image_url'] ?? ''),
                'servingUnit' => $row['serving_unit'] !== null ? (string) $row['serving_unit'] : null,
                'chefSpecial' => ((int) $row['chef_special']) === 1,
                'spiceLevel' => $row['spice_level'] !== null ? (string) $row['spice_level'] : null,
                'jainPrice' => $row['jain_price'] !== null ? (string) $row['jain_price'] : null,
                'isVeg' => ((int) $row['is_veg']) === 1,
                'rowDiet' => (string) $row['row_diet'],
                'descNonVeg' => ((int) $row['desc_non_veg']) === 1,
                'isAvailable' => ((int) $row['is_available']) === 1,
                'proteins' => $proteins,
                'servings' => $servings,
                'dynamic' => $dynamic,
                'prices' => $prices,
            ];
        }, $items);
    }

    public function applyCategoryOrder(int $menuSourceId, array $categories): int
    {
        $rows = $this->listAdminSnapshotRowsBySource($menuSourceId);
        if ($rows === []) {
            return 0;
        }

        $orderedCategories = [];
        foreach ($categories as $category) {
            $name = trim((string) $category);
            if ($name === '' || in_array($name, $orderedCategories, true)) {
                continue;
            }
            $orderedCategories[] = $name;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $categoryName = trim((string) ($row['categoryName'] ?? ''));
            if ($categoryName === '') {
                $categoryName = 'Uncategorized';
            }

            if (!isset($grouped[$categoryName])) {
                $grouped[$categoryName] = [];
            }
            $grouped[$categoryName][] = $row;
        }

        foreach (array_keys($grouped) as $categoryName) {
            if (!in_array($categoryName, $orderedCategories, true)) {
                $orderedCategories[] = $categoryName;
            }
        }

        $newSequence = [];
        foreach ($orderedCategories as $categoryName) {
            foreach (($grouped[$categoryName] ?? []) as $row) {
                $newSequence[] = $row;
            }
        }

        $updateStatement = $this->connection->prepare(
            'UPDATE menu_items SET row_index = :row_index WHERE id = :id AND menu_source_id = :menu_source_id'
        );

        $this->connection->beginTransaction();
        try {
            foreach ($newSequence as $index => $row) {
                $updateStatement->execute([
                    'row_index' => $index + 1,
                    'id' => (int) ($row['menuItemId'] ?? 0),
                    'menu_source_id' => $menuSourceId,
                ]);
            }

            if ($this->connection->inTransaction()) {
                $this->connection->commit();
            }

            return count($newSequence);
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function latestImportSummaryBySource(int $menuSourceId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM menu_import_runs WHERE menu_source_id = :menu_source_id ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['menu_source_id' => $menuSourceId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}