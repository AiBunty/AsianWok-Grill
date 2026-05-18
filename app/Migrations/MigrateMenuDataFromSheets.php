<?php

declare(strict_types=1);

namespace AWG\Migrations;

use AWG\Config\Database;
use AWG\Repositories\MenuManagementRepository;
use Throwable;

/**
 * Migration: Load menu data from Google Sheets into menu_items_v2
 * 
 * This script fetches menu data from Google Sheets using the gviz API
 * and imports it into the new menu_items_v2 and menu_item_variants_v2 tables.
 */
final class MigrateMenuDataFromSheets
{
    private const SHEET_SOURCES = [
        'menu_a' => [
            'sheetId' => '19hUSc2ny1NGd73WDTQfosdS3O7xhwiQbdGbiDgKSQlA',
            'sheetName' => 'MENU_A',
            'type' => 'gviz',
        ],
        'menu_b' => [
            'sheetId' => '1BbxQ-HN-QsknQAXGp75IpaGqfnD6b1acLPnMUdi5hAg',
            'sheetName' => 'MENU_B',
            'type' => 'gviz',
        ],
        'menu_c' => [
            'sheetId' => '1KA-aKRyCkGiAyUU991sAiO0NNY28oc-I2YYHfj2gqqE',
            'sheetName' => 'MENU_C',
            'type' => 'gviz',
        ],
    ];

    private MenuManagementRepository $repo;
    private \PDO $db;

    public function __construct()
    {
        try {
            $this->db = Database::connection();
            $this->repo = new MenuManagementRepository($this->db);
        } catch (Throwable $e) {
            echo "❌ Database connection failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * Run the migration for all menus
     */
    public function run(): void
    {
        echo "\n🚀 Starting menu data migration from Google Sheets...\n\n";

        foreach (self::SHEET_SOURCES as $menuType => $source) {
            $this->migrateMenu($menuType, $source);
        }

        echo "\n✅ Migration complete!\n";
    }

    /**
     * Migrate a single menu
     */
    private function migrateMenu(string $menuType, array $source): void
    {
        echo "📋 Processing {$menuType}...\n";

        try {
            // Fetch data from Google Sheets
            $sheetData = $this->fetchFromGoogleSheet($source['sheetId']);
            if (!$sheetData) {
                echo "  ⚠️  No data fetched from sheet.\n";
                return;
            }

            // Parse sheet data into menu items
            $items = $this->parseSheetData($menuType, $sheetData);
            if (empty($items)) {
                echo "  ⚠️  No items found in sheet.\n";
                return;
            }

            // Clear existing data for this menu
            $this->clearMenuData($menuType);

            // Import items
            $inserted = 0;
            $this->db->beginTransaction();

            try {
                foreach ($items as $rowIndex => $item) {
                    $item['source_row'] = $rowIndex;
                    $itemId = $this->repo->insertItem($menuType, $item);

                    // Handle variants
                    if (!empty($item['variants'])) {
                        $this->repo->replaceVariantsForItem($itemId, $item['variants']);
                    }

                    $inserted++;

                    if ($inserted % 10 === 0) {
                        echo "  ✓ Imported {$inserted} items...\n";
                    }
                }

                $this->db->commit();
                echo "  ✅ Successfully imported {$inserted} items to {$menuType}\n";
            } catch (Throwable $e) {
                $this->db->rollBack();
                echo "  ❌ Import failed: " . $e->getMessage() . "\n";
                throw $e;
            }
        } catch (Throwable $e) {
            echo "  ❌ Migration failed: " . $e->getMessage() . "\n";
        }

        echo "\n";
    }

    /**
     * Fetch data from Google Sheets via gviz API
     */
    private function fetchFromGoogleSheet(string $sheetId): ?array
    {
        $url = "https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq?tqx=out:json;reqId:1&tq=select+*&headers=1";

        try {
            $raw = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 30],
                'https' => ['timeout' => 30],
            ]));

            if (!$raw) {
                echo "  ⚠️  Could not fetch sheet data (network error)\n";
                return null;
            }

            // Extract JSON from gviz response
            $start = strpos($raw, '{');
            $end = strrpos($raw, '}');
            if ($start === false || $end === false) {
                echo "  ⚠️  Invalid response format from sheet\n";
                return null;
            }

            $json = substr($raw, $start, $end - $start + 1);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return $data;
        } catch (Throwable $e) {
            echo "  ⚠️  Error fetching sheet: " . $e->getMessage() . "\n";
            return null;
        }
    }

    /**
     * Parse Google Sheets data into menu item format
     */
    private function parseSheetData(string $menuType, array $sheetData): array
    {
        $items = [];
        $cols = $sheetData['table']['cols'] ?? [];
        $rows = $sheetData['table']['rows'] ?? [];

        // Build column index map
        $colMap = [];
        foreach ($cols as $idx => $col) {
            $label = trim((string) ($col['label'] ?? ''));
            if ($label) {
                $colMap[strtolower($label)] = $idx;
            }
        }

        if (empty($colMap)) {
            return [];
        }

        // Helper function to get column value
        $getCol = function ($row, $names) use ($colMap, $cols) {
            foreach ((array)$names as $name) {
                $idx = $colMap[strtolower($name)] ?? -1;
                if ($idx >= 0 && isset($row['c'][$idx]['v'])) {
                    $v = $row['c'][$idx]['v'];
                    return is_scalar($v) ? (string)$v : '';
                }
            }
            return '';
        };

        $getPrice = function ($row, $names) use ($colMap, $cols) {
            $val = '';
            foreach ((array)$names as $name) {
                $idx = $colMap[strtolower($name)] ?? -1;
                if ($idx >= 0 && isset($row['c'][$idx]['v'])) {
                    $v = $row['c'][$idx]['v'];
                    if (is_numeric($v)) {
                        return (float)$v;
                    }
                }
            }
            return null;
        };

        // Parse each row
        foreach ($rows as $rowIndex => $row) {
            $category = trim($getCol($row, ['Category', 'category name']));
            $itemName = trim($getCol($row, ['Item', 'Item Name', 'Name']));

            if (!$category || !$itemName) {
                continue;
            }

            $item = [
                'category' => $category,
                'item_name' => $itemName,
                'description' => trim($getCol($row, ['Description', 'Desc'])),
                'image_url' => trim($getCol($row, ['Image', 'Image URL'])),
                'is_available' => 1,
                'is_chef_special' => $this->isTruthy($getCol($row, ['Chef Special', "Chef's Special"])) ? 1 : 0,
                'is_veg' => $this->isTruthy($getCol($row, ['Veg', 'is_veg'])) ? 1 : 0,
                'is_nonveg' => $this->isTruthy($getCol($row, ['NonVeg', 'is_nonveg'])) ? 1 : 0,
                'is_jain' => $this->isTruthy($getCol($row, ['Jain', 'is_jain'])) ? 1 : 0,
                'is_universal' => 0,
                'primary_diet' => $this->determinePrimaryDiet(
                    $this->isTruthy($getCol($row, ['Veg', 'is_veg'])),
                    $this->isTruthy($getCol($row, ['NonVeg', 'is_nonveg'])),
                    $this->isTruthy($getCol($row, ['Jain', 'is_jain']))
                ),
                'pricing_mode' => 'direct',
                'price_direct' => $getPrice($row, ['Price', 'price', 'Direct Price']) ?? 0,
                'category_sort_order' => 0,
                'item_sort_order' => count($items),
                'manually_edited' => 0,
            ];

            // Set individual prices if available
            if ($price = $getPrice($row, ['Veg', 'veg price'])) {
                $item['price_veg'] = $price;
                $item['pricing_mode'] = 'diet_based';
            }
            if ($price = $getPrice($row, ['Chicken', 'chicken price'])) {
                $item['price_chicken'] = $price;
                $item['pricing_mode'] = 'diet_based';
            }
            if ($price = $getPrice($row, ['Prawn', 'prawn price'])) {
                $item['price_prawns'] = $price;
                $item['pricing_mode'] = 'diet_based';
            }
            if ($price = $getPrice($row, ['Mutton', 'mutton price'])) {
                $item['price_mutton'] = $price;
                $item['pricing_mode'] = 'diet_based';
            }

            // Parse variants
            $item['variants'] = $this->parseVariants($row, $colMap, $getPrice);

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Parse variant data from row
     */
    private function parseVariants(array $row, array $colMap, callable $getPrice): array
    {
        $variants = [];

        // Common variant patterns
        $variantPatterns = [
            ['Half', 'Full'],
            ['Plain', 'Butter'],
            ['Medium', 'Large'],
            ['2pcs', '4pcs', '6pcs', '9pcs', '12pcs'],
        ];

        foreach ($variantPatterns as $pattern) {
            foreach ($pattern as $variantName) {
                $idx = $colMap[strtolower($variantName)] ?? -1;
                if ($idx >= 0) {
                    $price = $getPrice($row, [$variantName]);
                    if ($price !== null) {
                        $variants[] = [
                            'variantLabel' => $variantName,
                            'price' => $price,
                        ];
                    }
                }
            }
        }

        return array_values(array_unique($variants, SORT_REGULAR));
    }

    /**
     * Determine primary diet type
     */
    private function determinePrimaryDiet(bool $veg, bool $nonveg, bool $jain): string
    {
        if ($jain) return 'jain';
        if ($veg) return 'veg';
        if ($nonveg) return 'nonveg';
        return 'universal';
    }

    /**
     * Check if value is truthy
     */
    private function isTruthy(string $val): bool
    {
        $val = strtolower(trim($val));
        return in_array($val, ['1', 'yes', 'true', 'y', 'on'], true);
    }

    /**
     * Clear existing menu data
     */
    private function clearMenuData(string $menuType): void
    {
        try {
            // Delete variants first (foreign key constraint)
            $deleteVars = $this->db->prepare(
                'DELETE v FROM menu_item_variants_v2 v 
                 INNER JOIN menu_items_v2 i ON i.id = v.item_id 
                 WHERE i.menu_type = :menu_type'
            );
            $deleteVars->execute(['menu_type' => $menuType]);

            // Delete items
            $deleteItems = $this->db->prepare(
                'DELETE FROM menu_items_v2 WHERE menu_type = :menu_type'
            );
            $deleteItems->execute(['menu_type' => $menuType]);

            echo "  ✓ Cleared existing data\n";
        } catch (Throwable $e) {
            echo "  ⚠️  Could not clear existing data: " . $e->getMessage() . "\n";
        }
    }
}

// Run migration if invoked from CLI
if (php_sapi_name() === 'cli' && basename($argv[0] ?? '') === basename(__FILE__)) {
    $migration = new MigrateMenuDataFromSheets();
    $migration->run();
}
