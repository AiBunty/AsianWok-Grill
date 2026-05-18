<?php

declare(strict_types=1);

namespace AWG\Repositories;

use PDO;
use Throwable;

final class MenuManagementRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function listMenuItems(string $menuType): array
    {
        $statement = $this->connection->prepare(
            'SELECT
                id,
                menu_type,
                category,
                item_name,
                description,
                image_url,
                is_available,
                is_chef_special,
                is_veg,
                is_nonveg,
                is_jain,
                is_universal,
                spice_level,
                primary_diet,
                pricing_mode,
                price_veg,
                price_jain,
                price_chicken,
                price_mutton,
                price_basa,
                price_prawns,
                price_surmai,
                price_pomfret,
                price_crab,
                price_egg,
                price_half,
                price_full,
                price_plain,
                price_butter,
                price_medium,
                price_large,
                price_direct,
                category_sort_order,
                item_sort_order,
                source_row,
                manually_edited,
                created_at,
                updated_at,
                CASE WHEN uploaded_image_webp IS NULL THEN 0 ELSE 1 END AS has_uploaded_image,
                uploaded_image_updated_at
            FROM menu_items_v2
            WHERE menu_type = :menu_type
            ORDER BY category_sort_order ASC, item_sort_order ASC, id ASC'
        );
        $statement->execute(['menu_type' => $menuType]);
        $items = $statement->fetchAll();
        if (!is_array($items) || $items === []) {
            return [];
        }

        $itemIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $items);
        $variantsByItem = $this->listVariantsByItemIds($itemIds);

        return array_map(function (array $row) use ($variantsByItem): array {
            $id = (int) ($row['id'] ?? 0);
            $row['variants'] = $variantsByItem[$id] ?? [];
            return $row;
        }, $items);
    }

    public function listCategories(string $menuType): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM menu_categories_v2 WHERE menu_type = :menu_type ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute(['menu_type' => $menuType]);
        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function ensureCategory(string $menuType, string $name): array
    {
        $existing = $this->findCategory($menuType, $name);
        if (is_array($existing)) {
            return $existing;
        }

        $maxSort = $this->connection->prepare('SELECT COALESCE(MAX(sort_order), -1) AS max_sort FROM menu_categories_v2 WHERE menu_type = :menu_type');
        $maxSort->execute(['menu_type' => $menuType]);
        $max = $maxSort->fetch();
        $sortOrder = (int) ($max['max_sort'] ?? -1) + 1;

        $insert = $this->connection->prepare(
            'INSERT INTO menu_categories_v2 (menu_type, name, sort_order, is_active, aliases_json) VALUES (:menu_type, :name, :sort_order, 1, :aliases_json)'
        );
        $insert->execute([
            'menu_type' => $menuType,
            'name' => $name,
            'sort_order' => $sortOrder,
            'aliases_json' => '[]',
        ]);

        return $this->findCategory($menuType, $name) ?? [
            'id' => (int) $this->connection->lastInsertId(),
            'menu_type' => $menuType,
            'name' => $name,
            'sort_order' => $sortOrder,
            'is_active' => 1,
            'aliases_json' => '[]',
        ];
    }

    public function saveChanges(string $menuType, array $changes): int
    {
        $updated = 0;
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $id = (int) ($change['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $payload = $this->toItemPayload($menuType, $change);
            $this->updateItemById($menuType, $id, $payload);
            if (array_key_exists('variants', $change) && is_array($change['variants'])) {
                $this->replaceVariantsForItem($id, $change['variants']);
            }
            $updated++;
        }

        return $updated;
    }

    public function insertItem(string $menuType, array $payload): int
    {
        $insert = $this->connection->prepare(
            'INSERT INTO menu_items_v2 (
                menu_type, category, item_name, description, image_url,
                uploaded_image_webp, uploaded_image_mime, uploaded_image_updated_at,
                is_available, is_chef_special, is_veg, is_nonveg, is_jain, is_universal, spice_level,
                primary_diet, pricing_mode,
                price_veg, price_jain, price_chicken, price_mutton, price_basa, price_prawns,
                price_surmai, price_pomfret, price_crab, price_egg, price_half, price_full,
                price_plain, price_butter, price_medium, price_large, price_direct,
                category_sort_order, item_sort_order, source_row, manually_edited
            ) VALUES (
                :menu_type, :category, :item_name, :description, :image_url,
                :uploaded_image_webp, :uploaded_image_mime, :uploaded_image_updated_at,
                :is_available, :is_chef_special, :is_veg, :is_nonveg, :is_jain, :is_universal, :spice_level,
                :primary_diet, :pricing_mode,
                :price_veg, :price_jain, :price_chicken, :price_mutton, :price_basa, :price_prawns,
                :price_surmai, :price_pomfret, :price_crab, :price_egg, :price_half, :price_full,
                :price_plain, :price_butter, :price_medium, :price_large, :price_direct,
                :category_sort_order, :item_sort_order, :source_row, :manually_edited
            )'
        );

        $insert->execute($this->toItemPayload($menuType, $payload));
        return (int) $this->connection->lastInsertId();
    }

    public function deleteRows(string $menuType, array $ids): int
    {
        $ids = array_values(array_unique(array_map(static fn ($v): int => (int) $v, $ids)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'DELETE FROM menu_items_v2 WHERE menu_type = ? AND id IN (' . $in . ')';
        $statement = $this->connection->prepare($sql);
        $statement->execute(array_merge([$menuType], $ids));
        return $statement->rowCount();
    }

    public function updateUploadedImage(string $menuType, int $id, string $webpBinary, string $mimeType = 'image/webp'): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE menu_items_v2 SET
                uploaded_image_webp = :uploaded_image_webp,
                uploaded_image_mime = :uploaded_image_mime,
                uploaded_image_updated_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
             WHERE menu_type = :menu_type AND id = :id'
        );

        return $statement->execute([
            'uploaded_image_webp' => $webpBinary,
            'uploaded_image_mime' => $mimeType,
            'menu_type' => $menuType,
            'id' => $id,
        ]);
    }

    public function getUploadedImage(string $menuType, int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT uploaded_image_webp, uploaded_image_mime, uploaded_image_updated_at
             FROM menu_items_v2
             WHERE menu_type = :menu_type AND id = :id
             LIMIT 1'
        );
        $statement->execute([
            'menu_type' => $menuType,
            'id' => $id,
        ]);

        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        if (!isset($row['uploaded_image_webp']) || $row['uploaded_image_webp'] === null || $row['uploaded_image_webp'] === '') {
            return null;
        }

        return [
            'binary' => (string) $row['uploaded_image_webp'],
            'mime' => (string) ($row['uploaded_image_mime'] ?? 'image/webp'),
            'updatedAt' => (string) ($row['uploaded_image_updated_at'] ?? ''),
        ];
    }

    public function setItemVisibility(string $menuType, array $ids, bool $isAvailable): int
    {
        $ids = array_values(array_unique(array_map(static fn ($v): int => (int) $v, $ids)));
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE menu_items_v2 SET is_available = ?, updated_at = CURRENT_TIMESTAMP WHERE menu_type = ? AND id IN (' . $in . ')';
        $statement = $this->connection->prepare($sql);
        $statement->execute(array_merge([$isAvailable ? 1 : 0, $menuType], $ids));
        return $statement->rowCount();
    }

    public function saveCategoryOrder(string $menuType, array $categories): int
    {
        $updated = 0;
        $statement = $this->connection->prepare(
            'UPDATE menu_categories_v2 SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP WHERE menu_type = :menu_type AND name = :name'
        );
        $itemStatement = $this->connection->prepare(
            'UPDATE menu_items_v2 SET category_sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP WHERE menu_type = :menu_type AND category = :category'
        );

        foreach (array_values($categories) as $sortOrder => $categoryName) {
            $categoryName = trim((string) $categoryName);
            if ($categoryName === '') {
                continue;
            }
            $this->ensureCategory($menuType, $categoryName);

            $statement->execute([
                'sort_order' => $sortOrder,
                'menu_type' => $menuType,
                'name' => $categoryName,
            ]);

            $itemStatement->execute([
                'sort_order' => $sortOrder,
                'menu_type' => $menuType,
                'category' => $categoryName,
            ]);

            $updated += $statement->rowCount();
        }

        return $updated;
    }

    public function saveItemOrder(string $menuType, array $items): int
    {
        $updated = 0;
        $statement = $this->connection->prepare(
            'UPDATE menu_items_v2 SET category = :category, category_sort_order = :category_sort_order, item_sort_order = :item_sort_order, updated_at = CURRENT_TIMESTAMP WHERE menu_type = :menu_type AND id = :id'
        );

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $statement->execute([
                'category' => trim((string) ($item['category'] ?? 'Uncategorized')),
                'category_sort_order' => (int) ($item['categorySortOrder'] ?? 0),
                'item_sort_order' => (int) ($item['itemSortOrder'] ?? 0),
                'menu_type' => $menuType,
                'id' => $id,
            ]);
            $updated += $statement->rowCount();
        }

        return $updated;
    }

    public function toggleCategory(string $menuType, string $categoryName, bool $isActive): int
    {
        $categoryName = trim($categoryName);
        if ($categoryName === '') {
            return 0;
        }

        $category = $this->ensureCategory($menuType, $categoryName);

        $statement = $this->connection->prepare(
            'UPDATE menu_categories_v2 SET is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'is_active' => $isActive ? 1 : 0,
            'id' => (int) ($category['id'] ?? 0),
        ]);

        return $statement->rowCount();
    }

    public function toggleItem(string $menuType, int $id, bool $isAvailable): int
    {
        $statement = $this->connection->prepare(
            'UPDATE menu_items_v2 SET is_available = :is_available, updated_at = CURRENT_TIMESTAMP WHERE menu_type = :menu_type AND id = :id'
        );
        $statement->execute([
            'is_available' => $isAvailable ? 1 : 0,
            'menu_type' => $menuType,
            'id' => $id,
        ]);

        return $statement->rowCount();
    }

    public function cloneCategory(string $menuType, string $sourceCategory, string $targetCategory, bool $cloneItems): array
    {
        $sourceCategory = trim($sourceCategory);
        $targetCategory = trim($targetCategory);
        if ($sourceCategory === '' || $targetCategory === '') {
            return ['categoryCreated' => false, 'itemsCloned' => 0];
        }

        $this->connection->beginTransaction();

        try {
            $category = $this->ensureCategory($menuType, $targetCategory);
            $categoryCreated = is_array($category);
            $itemsCloned = 0;

            if ($cloneItems) {
                $sourceItems = $this->listItemsByCategory($menuType, $sourceCategory);
                foreach ($sourceItems as $index => $item) {
                    $item['category'] = $targetCategory;
                    $item['item_sort_order'] = $index;
                    $item['itemSortOrder'] = $index;
                    $itemId = $this->insertItem($menuType, $item);
                    $this->replaceVariantsForItem($itemId, $item['variants'] ?? []);
                    $itemsCloned++;
                }
            }

            $this->connection->commit();
            return ['categoryCreated' => $categoryCreated, 'itemsCloned' => $itemsCloned];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function begin(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    public function findItemForImport(string $menuType, string $category, string $itemName, ?int $sourceRow): ?array
    {
        if ($sourceRow !== null) {
            $bySource = $this->connection->prepare(
                'SELECT * FROM menu_items_v2 WHERE menu_type = :menu_type AND source_row = :source_row LIMIT 1'
            );
            $bySource->execute(['menu_type' => $menuType, 'source_row' => $sourceRow]);
            $row = $bySource->fetch();
            if (is_array($row)) {
                return $row;
            }
        }

        $statement = $this->connection->prepare(
            'SELECT * FROM menu_items_v2 WHERE menu_type = :menu_type AND category = :category AND item_name = :item_name ORDER BY id ASC LIMIT 1'
        );
        $statement->execute([
            'menu_type' => $menuType,
            'category' => $category,
            'item_name' => $itemName,
        ]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function updateItemById(string $menuType, int $id, array $payload): void
    {
        unset($payload['variants']);

        $statement = $this->connection->prepare(
            'UPDATE menu_items_v2 SET
                category = :category,
                item_name = :item_name,
                description = :description,
                image_url = :image_url,
                is_available = :is_available,
                is_chef_special = :is_chef_special,
                is_veg = :is_veg,
                is_nonveg = :is_nonveg,
                is_jain = :is_jain,
                is_universal = :is_universal,
                primary_diet = :primary_diet,
                pricing_mode = :pricing_mode,
                price_veg = :price_veg,
                price_jain = :price_jain,
                price_chicken = :price_chicken,
                price_mutton = :price_mutton,
                price_basa = :price_basa,
                price_prawns = :price_prawns,
                price_surmai = :price_surmai,
                price_pomfret = :price_pomfret,
                price_crab = :price_crab,
                price_egg = :price_egg,
                price_half = :price_half,
                price_full = :price_full,
                price_plain = :price_plain,
                price_butter = :price_butter,
                price_medium = :price_medium,
                price_large = :price_large,
                price_direct = :price_direct,
                category_sort_order = :category_sort_order,
                item_sort_order = :item_sort_order,
                source_row = :source_row,
                manually_edited = :manually_edited,
                updated_at = CURRENT_TIMESTAMP
            WHERE menu_type = :menu_type AND id = :id'
        );

        $statement->execute(array_merge($payload, [
            'menu_type' => $menuType,
            'id' => $id,
        ]));
    }

    public function replaceVariantsForItem(int $itemId, array $variants): void
    {
        $delete = $this->connection->prepare('DELETE FROM menu_item_variants_v2 WHERE item_id = :item_id');
        $delete->execute(['item_id' => $itemId]);

        $insert = $this->connection->prepare(
            'INSERT INTO menu_item_variants_v2 (item_id, variant_label, price, variant_sort_order) VALUES (:item_id, :variant_label, :price, :variant_sort_order)'
        );

        foreach (array_values($variants) as $index => $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $label = trim((string) ($variant['variantLabel'] ?? $variant['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $insert->execute([
                'item_id' => $itemId,
                'variant_label' => $label,
                'price' => $this->nullableDecimal($variant['price'] ?? null),
                'variant_sort_order' => (int) ($variant['variantSortOrder'] ?? $index),
            ]);
        }
    }

    public function saveSnapshot(string $menuType, string $note, array $snapshotRows, string $createdBy): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO menu_import_snapshots_v2 (menu_type, note, snapshot_json, created_by) VALUES (:menu_type, :note, :snapshot_json, :created_by)'
        );
        $statement->execute([
            'menu_type' => $menuType,
            'note' => $note,
            'snapshot_json' => json_encode($snapshotRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_by' => $createdBy,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function findCategory(string $menuType, string $name): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM menu_categories_v2 WHERE menu_type = :menu_type AND name = :name LIMIT 1'
        );
        $statement->execute([
            'menu_type' => $menuType,
            'name' => $name,
        ]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    private function listItemsByCategory(string $menuType, string $category): array
    {
        $statement = $this->connection->prepare(
            'SELECT * FROM menu_items_v2 WHERE menu_type = :menu_type AND category = :category ORDER BY item_sort_order ASC, id ASC'
        );
        $statement->execute([
            'menu_type' => $menuType,
            'category' => $category,
        ]);
        $rows = $statement->fetchAll();
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $itemIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        $variantsByItem = $this->listVariantsByItemIds($itemIds);
        return array_map(function (array $row) use ($variantsByItem): array {
            $row['variants'] = $variantsByItem[(int) ($row['id'] ?? 0)] ?? [];
            return $row;
        }, $rows);
    }

    private function listVariantsByItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $itemIds), static fn (int $id): bool => $id > 0));
        if ($itemIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($itemIds), '?'));
        $statement = $this->connection->prepare(
            'SELECT * FROM menu_item_variants_v2 WHERE item_id IN (' . $in . ') ORDER BY variant_sort_order ASC, id ASC'
        );
        $statement->execute($itemIds);
        $rows = $statement->fetchAll();

        $mapped = [];
        foreach (($rows ?: []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemId = (int) ($row['item_id'] ?? 0);
            $mapped[$itemId][] = [
                'id' => (int) ($row['id'] ?? 0),
                'variantLabel' => (string) ($row['variant_label'] ?? ''),
                'price' => $row['price'] !== null ? (float) $row['price'] : null,
                'variantSortOrder' => (int) ($row['variant_sort_order'] ?? 0),
            ];
        }

        return $mapped;
    }

    private function toItemPayload(string $menuType, array $row): array
    {
        $category = trim((string) ($row['category'] ?? $row['categoryName'] ?? 'Uncategorized'));
        $itemName = trim((string) ($row['item_name'] ?? $row['itemName'] ?? ''));

        return [
            'menu_type' => $menuType,
            'category' => $category !== '' ? $category : 'Uncategorized',
            'item_name' => $itemName,
            'description' => $this->nullableString($row['description'] ?? null),
            'image_url' => $this->nullableString($row['image_url'] ?? $row['imageUrl'] ?? null),
            'uploaded_image_webp' => $row['uploaded_image_webp'] ?? null,
            'uploaded_image_mime' => $this->nullableString($row['uploaded_image_mime'] ?? null),
            'uploaded_image_updated_at' => $this->nullableString($row['uploaded_image_updated_at'] ?? null),
            'is_available' => $this->toBoolInt($row['is_available'] ?? $row['isAvailable'] ?? true),
            'is_chef_special' => $this->toBoolInt($row['is_chef_special'] ?? $row['isChefSpecial'] ?? false),
            'is_veg' => $this->toBoolInt($row['is_veg'] ?? $row['isVeg'] ?? false),
            'is_nonveg' => $this->toBoolInt($row['is_nonveg'] ?? $row['isNonveg'] ?? false),
            'is_jain' => $this->toBoolInt($row['is_jain'] ?? $row['isJain'] ?? false),
            'is_universal' => $this->toBoolInt($row['is_universal'] ?? $row['isUniversal'] ?? false),
            'spice_level' => trim((string) ($row['spice_level'] ?? $row['spiceLevel'] ?? '')),
            'primary_diet' => (string) ($row['primary_diet'] ?? $row['primaryDiet'] ?? ''),
            'pricing_mode' => (string) ($row['pricing_mode'] ?? $row['pricingMode'] ?? 'standard'),
            'price_veg' => $this->nullableDecimal($row['price_veg'] ?? $row['priceVeg'] ?? null),
            'price_jain' => $this->nullableDecimal($row['price_jain'] ?? $row['priceJain'] ?? null),
            'price_chicken' => $this->nullableDecimal($row['price_chicken'] ?? $row['priceChicken'] ?? null),
            'price_mutton' => $this->nullableDecimal($row['price_mutton'] ?? $row['priceMutton'] ?? null),
            'price_basa' => $this->nullableDecimal($row['price_basa'] ?? $row['priceBasa'] ?? null),
            'price_prawns' => $this->nullableDecimal($row['price_prawns'] ?? $row['pricePrawns'] ?? null),
            'price_surmai' => $this->nullableDecimal($row['price_surmai'] ?? $row['priceSurmai'] ?? null),
            'price_pomfret' => $this->nullableDecimal($row['price_pomfret'] ?? $row['pricePomfret'] ?? null),
            'price_crab' => $this->nullableDecimal($row['price_crab'] ?? $row['priceCrab'] ?? null),
            'price_egg' => $this->nullableDecimal($row['price_egg'] ?? $row['priceEgg'] ?? null),
            'price_half' => $this->nullableDecimal($row['price_half'] ?? $row['priceHalf'] ?? null),
            'price_full' => $this->nullableDecimal($row['price_full'] ?? $row['priceFull'] ?? null),
            'price_plain' => $this->nullableDecimal($row['price_plain'] ?? $row['pricePlain'] ?? null),
            'price_butter' => $this->nullableDecimal($row['price_butter'] ?? $row['priceButter'] ?? null),
            'price_medium' => $this->nullableDecimal($row['price_medium'] ?? $row['priceMedium'] ?? null),
            'price_large' => $this->nullableDecimal($row['price_large'] ?? $row['priceLarge'] ?? null),
            'price_direct' => $this->nullableDecimal($row['price_direct'] ?? $row['priceDirect'] ?? null),
            'category_sort_order' => (int) ($row['category_sort_order'] ?? $row['categorySortOrder'] ?? 0),
            'item_sort_order' => (int) ($row['item_sort_order'] ?? $row['itemSortOrder'] ?? 0),
            'source_row' => array_key_exists('source_row', $row) ? $this->nullableInt($row['source_row']) : $this->nullableInt($row['sourceRow'] ?? null),
            'manually_edited' => $this->toBoolInt($row['manually_edited'] ?? $row['manuallyEdited'] ?? true),
        ];
    }

    private function toBoolInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $text = strtolower(trim((string) $value));
        return in_array($text, ['1', 'true', 'yes', 'y', 'on'], true) ? 1 : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function nullableDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([',', ' '], '', trim($value));
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
