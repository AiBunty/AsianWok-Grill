<?php

declare(strict_types=1);

namespace AWG\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class SimpleXlsx
{
    public static function readSheets(string $filePath): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('Uploaded file not found.');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Unable to open workbook. Please upload a valid .xlsx file.');
        }

        $sharedStrings = self::readSharedStrings($zip);
        $workbook = self::readXmlEntry($zip, 'xl/workbook.xml');
        $relationships = self::readRelationships($zip);

        $sheets = [];
        foreach ($workbook->sheets->sheet as $sheetNode) {
            $sheetName = (string) ($sheetNode['name'] ?? '');
            $relationshipId = (string) ($sheetNode->attributes('r', true)['id'] ?? '');
            if ($sheetName === '' || $relationshipId === '' || !isset($relationships[$relationshipId])) {
                continue;
            }

            $sheetPath = 'xl/' . ltrim($relationships[$relationshipId], '/');
            $sheetXml = self::readXmlEntry($zip, $sheetPath);
            $sheets[$sheetName] = self::readSheetRows($sheetXml, $sharedStrings);
        }

        $zip->close();
        return $sheets;
    }

    public static function writeWorkbook(array $sheets): string
    {
        if ($sheets === []) {
            throw new RuntimeException('No sheets provided to write workbook.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'awg_xlsx_');
        if ($tmpFile === false) {
            throw new RuntimeException('Unable to create temporary workbook file.');
        }

        $zipPath = $tmpFile . '.xlsx';
        @unlink($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create workbook archive.');
        }

        $sheetNames = array_keys($sheets);

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml(count($sheetNames)));
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($sheetNames));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml(count($sheetNames)));
        $zip->addFromString('xl/styles.xml', self::stylesXml());

        foreach ($sheetNames as $index => $sheetName) {
            $sheetRows = is_array($sheets[$sheetName]) ? $sheets[$sheetName] : [];
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', self::sheetXml($sheetRows));
        }

        $zip->close();

        $contents = file_get_contents($zipPath);
        @unlink($tmpFile);
        @unlink($zipPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to finalize workbook content.');
        }

        return $contents;
    }

    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xml = self::readXmlEntry($zip, 'xl/sharedStrings.xml', true);
        if ($xml === null) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            $parts = [];
            foreach ($si->r as $run) {
                $parts[] = (string) ($run->t ?? '');
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private static function readRelationships(ZipArchive $zip): array
    {
        $xml = self::readXmlEntry($zip, 'xl/_rels/workbook.xml.rels');
        $map = [];

        foreach ($xml->Relationship as $relationship) {
            $id = (string) ($relationship['Id'] ?? '');
            $target = (string) ($relationship['Target'] ?? '');
            if ($id !== '' && $target !== '') {
                $map[$id] = $target;
            }
        }

        return $map;
    }

    private static function readSheetRows(SimpleXMLElement $sheetXml, array $sharedStrings): array
    {
        $rows = [];
        $sheetData = $sheetXml->sheetData;
        if (!isset($sheetData)) {
            return $rows;
        }

        foreach ($sheetData->row as $rowNode) {
            $row = [];
            foreach ($rowNode->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $columnIndex = self::columnIndexFromReference($reference);
                if ($columnIndex < 0) {
                    continue;
                }

                $type = (string) ($cell['t'] ?? '');
                $value = '';

                if ($type === 's') {
                    $sharedIndex = (int) ($cell->v ?? -1);
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } elseif (isset($cell->v)) {
                    $value = (string) $cell->v;
                }

                $row[$columnIndex] = $value;
            }

            if ($row !== []) {
                ksort($row);
                $max = max(array_keys($row));
                $normalized = array_fill(0, $max + 1, '');
                foreach ($row as $index => $value) {
                    $normalized[$index] = $value;
                }
                $rows[] = $normalized;
            } else {
                $rows[] = [];
            }
        }

        return $rows;
    }

    private static function readXmlEntry(ZipArchive $zip, string $entry, bool $allowMissing = false): ?SimpleXMLElement
    {
        $raw = $zip->getFromName($entry);
        if ($raw === false) {
            if ($allowMissing) {
                return null;
            }
            throw new RuntimeException('Workbook is missing entry: ' . $entry);
        }

        $xml = simplexml_load_string($raw);
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Invalid XML entry in workbook: ' . $entry);
        }

        return $xml;
    }

    private static function columnIndexFromReference(string $reference): int
    {
        if ($reference === '') {
            return -1;
        }

        if (!preg_match('/^([A-Z]+)\d+$/i', $reference, $matches)) {
            return -1;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private static function contentTypesXml(int $sheetCount): string
    {
        $overrides = [];
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides[] = '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . implode('', $overrides)
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(array $sheetNames): string
    {
        $sheetParts = [];
        foreach (array_values($sheetNames) as $i => $name) {
            $safeName = htmlspecialchars((string) $name, ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $sheetParts[] = '<sheet name="' . $safeName . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . implode('', $sheetParts) . '</sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(int $sheetCount): string
    {
        $rels = [];
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels[] = '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rels[] = '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', $rels)
            . '</Relationships>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }

    private static function sheetXml(array $rows): string
    {
        $rowXml = [];
        foreach (array_values($rows) as $rowIndex => $row) {
            $cells = [];
            foreach (array_values(is_array($row) ? $row : []) as $colIndex => $value) {
                $reference = self::columnLetters($colIndex + 1) . ($rowIndex + 1);
                if ($value === null || $value === '') {
                    continue;
                }

                if (is_numeric($value) && !preg_match('/^0\d+$/', (string) $value)) {
                    $cells[] = '<c r="' . $reference . '"><v>' . htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</v></c>';
                    continue;
                }

                $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $cells[] = '<c r="' . $reference . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
            }

            $rowXml[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . implode('', $rowXml) . '</sheetData>'
            . '</worksheet>';
    }

    private static function columnLetters(int $number): string
    {
        $letters = '';
        while ($number > 0) {
            $mod = ($number - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $number = intdiv($number - $mod, 26);
        }

        return $letters;
    }
}
