<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Config\Database;
use AWG\Repositories\MenuRepository;
use RuntimeException;
use Throwable;

final class MenuImportService
{
    private const PROTEIN_COLUMNS = ['Veg', 'Chicken', 'Prawn', 'Mutton', 'Fish', 'Surmai', 'Pomfret', 'Crab', 'Egg'];
    private const VEG_XPCS_COLS = ['Veg 2Pcs', 'Veg 4pcs', 'Veg 6pcs', 'Veg 9pcs', 'Veg 12pcs'];
    private const SERVING_COLUMNS = [
        'Unit (Pcs)',
        'Veg 2Pcs',
        'Veg 4pcs',
        'Veg 6pcs',
        'Veg 9pcs',
        'Veg 12pcs',
        'Chicken 2pcs',
        'Chicken 4pcs',
        'Chicken 6pcs',
        'Chicken 9pcs',
        'Chicken 12pcs',
        'Prawns 2pcs',
        'Prawns 4pcs',
        'Prawns 6pcs',
        'Prawns 9pcs',
        'Prawns 12pcs',
        'Half',
        'Full',
        'Plain',
        'Butter',
        'Medium',
        'Large',
    ];
    private const KNOWN_STATIC = [
        'Item Name',
        'Description',
        'Category',
        'Image URL',
        'Jain',
        'Chef Special',
        "Chef's Special",
        'Spice Level',
        'Unit (Pcs)',
    ];
    private const REQUIRED_HEADERS = ['Item Name', 'Description', 'Category', 'Image URL'];
    private const DEFAULT_NON_VEG = ['chicken', 'mutton', 'prawn', 'prawns', 'fish', 'egg', 'eggs', 'surmai', 'pomfret', 'crab', 'meat', 'shrimp'];
    private const COCKTAIL_UNITS = ['Glass', '30ml', 'Pint', 'Pitcher', 'Per Bottle'];

    public function importAll(): array
    {
        $repository = new MenuRepository(Database::connection());
        $sources = $repository->listActiveSources();

        $results = [];
        foreach ($sources as $source) {
            if (!is_array($source) || empty($source['source_key'])) {
                continue;
            }
            $results[] = $this->importSourceByKey((string) $source['source_key']);
        }

        return [
            'ok' => true,
            'results' => $results,
        ];
    }

    public function importSourceByKey(string $sourceKey): array
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
        $source = $repository->findSourceByKey($sourceKey);
        if (!is_array($source)) {
            return [
                'ok' => false,
                'error' => 'SOURCE_NOT_FOUND',
                'message' => 'Menu source was not found.',
                'sourceKey' => $sourceKey,
            ];
        }

        $payload = $this->fetchSourcePayload((string) $source['source_url']);
        $runId = $repository->startImportRun(
            (int) $source['id'],
            strlen($payload),
            hash('sha256', $payload)
        );

        try {
            $items = ((string) $source['source_type'] === 'csv_appscript')
                ? $this->parseCocktailCsvPayload($payload)
                : $this->parseGvizPayload($payload);

            $persisted = $repository->replaceSourceItems((int) $source['id'], $items);

            $summary = [
                'sourceKey' => $sourceKey,
                'sourceType' => (string) $source['source_type'],
                'itemCount' => count($items),
                'variantCount' => (int) ($persisted['variants'] ?? 0),
                'categoryCount' => count(array_unique(array_map(
                    static fn (array $item): string => (string) ($item['category'] ?? 'Other'),
                    $items
                ))),
            ];

            $repository->markImportRunSuccess(
                $runId,
                (int) ($persisted['items'] ?? 0),
                (int) ($persisted['variants'] ?? 0),
                $summary
            );

            return [
                'ok' => true,
                'result' => 'import_success',
                'sourceKey' => $sourceKey,
                'runId' => $runId,
                'itemCount' => (int) ($persisted['items'] ?? 0),
                'variantCount' => (int) ($persisted['variants'] ?? 0),
                'summary' => $summary,
            ];
        } catch (Throwable $exception) {
            $repository->markImportRunFailed($runId, $exception->getMessage());

            return [
                'ok' => false,
                'error' => 'IMPORT_FAILED',
                'message' => $exception->getMessage(),
                'sourceKey' => $sourceKey,
                'runId' => $runId,
            ];
        }
    }

    private function fetchSourcePayload(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('Menu source URL is empty.');
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize source fetch request.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_HTTPHEADER => ['Accept: */*'],
            ]);

            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $error !== '') {
                throw new RuntimeException($error !== '' ? $error : 'Source fetch failed.');
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                throw new RuntimeException('Source fetch failed with HTTP ' . $httpCode . '.');
            }

            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => "Accept: */*\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Source fetch failed.');
        }

        $httpCode = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', (string) $line, $matches) === 1) {
                $httpCode = (int) $matches[1];
            }
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Source fetch failed with HTTP ' . $httpCode . '.');
        }

        return (string) $body;
    }

    private function parseGvizPayload(string $payload): array
    {
        $json = $this->extractJsonFromGviz($payload);
        $rows = $json['table']['rows'] ?? [];
        $cols = $json['table']['cols'] ?? [];

        if (!is_array($rows) || !is_array($cols) || $cols === []) {
            throw new RuntimeException('GViz source did not return expected table data.');
        }

        [$map, $headerList] = $this->buildHeaderMapFromCols($cols);
        [$valid, $missing, $dataRows, $currentMap, $currentHeaders] = $this->validateHeaders($map, $headerList, $rows, $cols);

        if (!$valid) {
            throw new RuntimeException('GViz schema mismatch. Missing: ' . implode(', ', $missing));
        }

        $items = $this->parseFoodRows($dataRows, $currentMap, $currentHeaders);
        if ($items === []) {
            throw new RuntimeException('No food menu items parsed from source.');
        }

        return $items;
    }

    private function parseCocktailCsvPayload(string $payload): array
    {
        $rows = $this->parseCsv($payload);
        if ($rows === []) {
            throw new RuntimeException('No cocktail rows parsed from source.');
        }

        $headers = array_map(
            static fn ($header): string => strtolower(trim((string) $header)),
            $rows[0] ?? []
        );

        $items = [];
        foreach (array_slice($rows, 1) as $index => $row) {
            $record = [];
            foreach ($headers as $headerIndex => $header) {
                $record[$header] = trim((string) ($row[$headerIndex] ?? ''));
            }

            $name = (string) ($record['item name'] ?? '');
            if ($name === '') {
                continue;
            }

            $availability = strtolower((string) ($record['availability'] ?? 'Yes'));
            $isAvailable = $availability !== 'no';
            if (!$isAvailable) {
                continue;
            }

            $prices = [];
            foreach (self::COCKTAIL_UNITS as $unit) {
                $raw = (string) ($record[strtolower($unit)] ?? '');
                $cleaned = str_replace(['₹', ',', ' '], '', $raw);
                if ($cleaned === '' || !preg_match('/^\d+(\.\d+)?$/', $cleaned)) {
                    continue;
                }
                $prices[$unit] = $cleaned;
            }

            if ($prices === []) {
                continue;
            }

            $items[] = [
                'category' => (string) ($record['category'] ?? 'Selection'),
                'name' => $name,
                'description' => (string) ($record['description'] ?? ''),
                'prices' => $prices,
                'isAvailable' => true,
                'rowIndex' => $index,
                'raw' => $record,
            ];
        }

        if ($items === []) {
            throw new RuntimeException('No cocktail menu items parsed from source.');
        }

        return $items;
    }

    private function extractJsonFromGviz(string $payload): array
    {
        $start = strpos($payload, '{');
        $end = strrpos($payload, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('Unable to parse GViz payload.');
        }

        $decoded = json_decode(substr($payload, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GViz payload is not valid JSON.');
        }

        return $decoded;
    }

    private function buildHeaderMapFromCols(array $columns): array
    {
        $headerList = array_map(static function ($column, int $index): string {
            if (is_array($column) && !empty($column['label'])) {
                return trim((string) $column['label']);
            }
            if (is_array($column) && !empty($column['id'])) {
                return 'Col_' . (string) $column['id'];
            }
            return 'Col_' . ($index + 1);
        }, $columns, array_keys($columns));

        $map = [];
        foreach ($headerList as $index => $name) {
            $map[$name] = $index;
        }

        return [$map, $headerList];
    }

    private function buildHeaderMapFromFirstRow(array $firstRow, int $columnCount): array
    {
        $headerList = [];
        for ($index = 0; $index < $columnCount; $index++) {
            $value = $firstRow['c'][$index]['v'] ?? '';
            $text = trim((string) $value);
            $headerList[] = $text !== '' ? $text : 'Col_' . ($index + 1);
        }

        $map = [];
        foreach ($headerList as $index => $name) {
            $map[$name] = $index;
        }

        return [$map, $headerList];
    }

    private function validateHeaders(array $map, array $headerList, array $rows, array $cols): array
    {
        $dataRows = $rows;
        $currentMap = $map;
        $currentHeaders = $headerList;

        $missing = array_values(array_filter(self::REQUIRED_HEADERS, function (string $header) use ($currentMap): bool {
            return $this->getColumnIndex($currentMap, $header) === null;
        }));

        if ($missing !== [] && $rows !== []) {
            [$firstMap, $firstHeaders] = $this->buildHeaderMapFromFirstRow($rows[0], count($cols));
            $missingFromFirst = array_values(array_filter(self::REQUIRED_HEADERS, function (string $header) use ($firstMap): bool {
                return $this->getColumnIndex($firstMap, $header) === null;
            }));

            if (count($missingFromFirst) < count($missing)) {
                $currentMap = $firstMap;
                $currentHeaders = $firstHeaders;
                $dataRows = array_slice($rows, 1);
                $missing = $missingFromFirst;
            }
        }

        return [$missing === [], $missing, $dataRows, $currentMap, $currentHeaders];
    }

    private function parseFoodRows(array $dataRows, array $map, array $headerList): array
    {
        $idxItemName = $this->getColumnIndex($map, 'Item Name');
        $idxDescription = $this->getColumnIndex($map, 'Description');
        $idxCategory = $this->getColumnIndex($map, 'Category');
        $idxImageUrl = $this->getColumnIndex($map, 'Image URL');
        $idxJain = $this->getColumnIndex($map, 'Jain');
        $idxUnitPcs = $this->getColumnIndex($map, 'Unit (Pcs)');
        $idxSpice = $this->getColumnIndex($map, 'Spice Level');
        $idxChef = $this->getColumnIndex($map, 'Chef Special');
        $idxChefAlt = $this->getColumnIndex($map, "Chef's Special");

        $idxChefGeneric = null;
        foreach ($headerList as $header) {
            $token = $this->normalizeHeader($header);
            if (str_contains($token, 'chef') && str_contains($token, 'special')) {
                $idxChefGeneric = $this->getColumnIndex($map, $header);
                break;
            }
        }

        $chefIndex = $idxChef ?? $idxChefAlt ?? $idxChefGeneric;

        $nonVegWords = self::DEFAULT_NON_VEG;
        $idxNonVegKeywords = $this->getColumnIndex($map, 'NonVeg Keywords');
        if ($idxNonVegKeywords !== null) {
            foreach ($dataRows as $row) {
                $value = $this->getCellValue($row['c'] ?? [], $idxNonVegKeywords);
                $value = trim((string) ($value ?? ''));
                if ($value === '') {
                    continue;
                }

                $tokens = array_values(array_filter(array_map(
                    static fn (string $token): string => strtolower(trim($token)),
                    preg_split('/[,;]+/', $value) ?: []
                )));

                if ($tokens !== []) {
                    $nonVegWords = $tokens;
                }
                break;
            }
        }

        $dynamicColumns = [];
        foreach ($headerList as $header) {
            if (in_array($header, self::KNOWN_STATIC, true) || in_array($header, self::SERVING_COLUMNS, true) || in_array($header, self::PROTEIN_COLUMNS, true)) {
                continue;
            }

            $type = 'meta';
            if (preg_match('/\b(veg|chicken|prawn|mutton|fish|surmai|pomfret|crab|egg)\b/i', $header) === 1) {
                $type = 'protein';
            } elseif (preg_match('/\b(pcs|pc|piece|half|full|plain|butter|medium|large)\b/i', $header) === 1) {
                $type = 'serving';
            } else {
                $idx = $this->getColumnIndex($map, $header);
                $sample = '';
                if ($idx !== null) {
                    foreach (array_slice($dataRows, 0, 6) as $sampleRow) {
                        $sample .= ' ' . (string) ($this->getCellValue($sampleRow['c'] ?? [], $idx) ?? '');
                    }
                }

                if (preg_match('/\d/', $sample) === 1) {
                    $type = 'price';
                }
            }

            $dynamicColumns[] = [
                'key' => $header,
                'type' => $type,
            ];
        }

        $items = [];
        foreach ($dataRows as $rowIndex => $row) {
            $cells = $row['c'] ?? [];
            $rawName = $this->getCellValue($cells, $idxItemName);
            $itemName = trim((string) ($rawName ?? ''));
            if ($itemName === '') {
                continue;
            }

            $description = (string) ($this->getCellValue($cells, $idxDescription) ?? '');
            $fullText = strtolower($itemName . ' ' . $description);
            $isSpecificVeg = str_contains($fullText, 'veg delight') || str_contains($fullText, 'mushroom veg');

            $nonVegInText = false;
            foreach ($nonVegWords as $word) {
                if ($word !== '' && str_contains($fullText, $word)) {
                    $nonVegInText = true;
                    break;
                }
            }

            $proteins = [];
            foreach (self::PROTEIN_COLUMNS as $column) {
                $idx = $this->getColumnIndex($map, $column);
                if ($idx === null) {
                    continue;
                }

                $value = $this->getCellValue($cells, $idx);
                if ($value === null || $value === '') {
                    continue;
                }

                $proteins[$column] = (string) $value;
            }

            $servings = [];
            foreach (self::SERVING_COLUMNS as $column) {
                $idx = $this->getColumnIndex($map, $column);
                if ($idx === null) {
                    continue;
                }

                $value = $this->getCellValue($cells, $idx);
                if ($value === null || $value === '') {
                    continue;
                }

                $servings[$column] = (string) $value;
            }

            $dynamic = [];
            foreach ($dynamicColumns as $dynamicColumn) {
                $idx = $this->getColumnIndex($map, $dynamicColumn['key']);
                if ($idx === null) {
                    continue;
                }

                $value = $this->getCellValue($cells, $idx);
                if ($value === null || $value === '') {
                    continue;
                }

                if ($dynamicColumn['type'] === 'protein') {
                    $proteins[$dynamicColumn['key']] = (string) $value;
                } elseif ($dynamicColumn['type'] === 'serving' || $dynamicColumn['type'] === 'price') {
                    $servings[$dynamicColumn['key']] = (string) $value;
                } else {
                    if ($this->parsePriceValue($value) !== null) {
                        $dynamic[$dynamicColumn['key']] = (string) $value;
                    }
                }
            }

            $nonVegFromProtein = false;
            foreach ($proteins as $proteinValue) {
                $token = strtolower((string) $proteinValue);
                foreach ($nonVegWords as $word) {
                    if ($word !== '' && str_contains($token, $word)) {
                        $nonVegFromProtein = true;
                        break 2;
                    }
                }
            }

            $isNonVegItem = !$isSpecificVeg && ($nonVegInText || $nonVegFromProtein);

            $items[] = [
                'category' => (string) ($this->getCellValue($cells, $idxCategory) ?? 'Other'),
                'name' => $itemName,
                'description' => $description,
                'imageUrl' => (string) ($this->getCellValue($cells, $idxImageUrl) ?? 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?w=400'),
                'servingUnit' => $this->getCellValue($cells, $idxUnitPcs),
                'chefSpecial' => $this->isChefSpecialValue($this->getCellValue($cells, $chefIndex)),
                'spiceLevel' => (string) ($this->getCellValue($cells, $idxSpice) ?? ''),
                'jainPrice' => $this->normalizeNullableValue($this->getCellValue($cells, $idxJain)),
                'isVeg' => !$isNonVegItem,
                'rowDiet' => $isNonVegItem ? 'nonveg' : 'veg',
                'descNonVeg' => $nonVegInText,
                'proteins' => $proteins,
                'servings' => $servings,
                'dynamic' => $dynamic,
                'rowIndex' => $rowIndex,
                'raw' => $row,
            ];
        }

        return $items;
    }

    private function parseCsv(string $payload): array
    {
        $rows = [];
        $currentRow = 0;
        $currentCol = 0;
        $inQuotes = false;
        $length = strlen($payload);

        for ($index = 0; $index < $length; $index++) {
            $char = $payload[$index];
            $next = $payload[$index + 1] ?? null;

            if (!isset($rows[$currentRow])) {
                $rows[$currentRow] = [];
            }
            if (!isset($rows[$currentRow][$currentCol])) {
                $rows[$currentRow][$currentCol] = '';
            }

            if ($char === '"' && $inQuotes && $next === '"') {
                $rows[$currentRow][$currentCol] .= '"';
                $index++;
                continue;
            }

            if ($char === '"') {
                $inQuotes = !$inQuotes;
                continue;
            }

            if ($char === ',' && !$inQuotes) {
                $currentCol++;
                continue;
            }

            if ($char === "\n" && !$inQuotes) {
                $currentRow++;
                $currentCol = 0;
                continue;
            }

            if ($char !== "\r") {
                $rows[$currentRow][$currentCol] .= $char;
            }
        }

        return $rows;
    }

    private function getColumnIndex(array $columnMap, string $name): ?int
    {
        if (array_key_exists($name, $columnMap)) {
            return (int) $columnMap[$name];
        }

        $wanted = $this->normalizeHeader($name);
        foreach ($columnMap as $columnName => $index) {
            if ($this->normalizeHeader((string) $columnName) === $wanted) {
                return (int) $index;
            }
        }

        return null;
    }

    private function normalizeHeader(string $name): string
    {
        $lower = strtolower(trim($name));
        return preg_replace('/\s+/', ' ', $lower) ?? $lower;
    }

    private function normalizeNullableValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function getCellValue(array $cells, ?int $index): mixed
    {
        if ($index === null || !isset($cells[$index]) || !is_array($cells[$index])) {
            return null;
        }

        $cell = $cells[$index];
        if (array_key_exists('v', $cell) && $cell['v'] !== null) {
            return $cell['v'];
        }

        if (array_key_exists('f', $cell) && $cell['f'] !== null) {
            return $cell['f'];
        }

        return null;
    }

    private function parsePriceValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        $cleaned = str_replace([',', ' ', '₹'], '', trim((string) $value));
        if ($cleaned === '' || preg_match('/^\d+(\.\d+)?$/', $cleaned) !== 1) {
            return null;
        }

        $number = (float) $cleaned;
        return is_finite($number) ? $number : null;
    }

    private function isChefSpecialValue(mixed $value): bool
    {
        $token = strtolower(trim((string) ($value ?? '')));
        $token = preg_replace('/\s+/', ' ', $token) ?? $token;
        return $token === 'yes';
    }
}