<?php

namespace App\Modules\Logistics\Application\CarrierBilling;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use XMLReader;
use ZipArchive;

final class CarrierBillingFileReader
{
    public const PARSER_VERSION = 'carrier-bill-v1';

    private const MAX_FILE_BYTES = 16 * 1024 * 1024;
    private const MAX_ROWS = 50000;
    private const MAX_COLUMNS = 64;
    private const MAX_ZIP_ENTRIES = 200;
    private const MAX_UNCOMPRESSED = 64 * 1024 * 1024;

    private const REQUIRED = ['tracking_number', 'actual_cost', 'currency'];
    private const OPTIONAL = [
        'chargeable_weight_kg', 'billing_document_number', 'provider_reference',
        'billed_at', 'delivery_status', 'carrier', 'charge_description',
    ];

    /** @return list<array{source_row_number:int,raw:array<string,?string>,values:array<string,?string>}> */
    public function read(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new InvalidArgumentException('Carrier bill file is not readable.');
        }
        $size = filesize($real);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('Carrier bill file size is invalid or exceeds the limit.');
        }

        return match (strtolower(pathinfo($real, PATHINFO_EXTENSION))) {
            'csv' => $this->readCsv($real),
            'xlsx' => $this->readXlsx($real),
            'xls' => throw new InvalidArgumentException('Legacy binary XLS is not supported; convert the carrier bill to CSV or XLSX.'),
            default => throw new InvalidArgumentException('Only CSV or XLSX carrier bills are supported.'),
        };
    }

    /** @return list<array{source_row_number:int,raw:array<string,?string>,values:array<string,?string>}> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new InvalidArgumentException('Carrier bill CSV cannot be opened.');
        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) throw new InvalidArgumentException('Carrier bill CSV is empty.');
            if (count($headers) > self::MAX_COLUMNS) throw new InvalidArgumentException('Carrier bill CSV has too many columns.');
            [$headerMap, $rawHeaders] = $this->headerMap(array_map(fn ($v): string => (string) $v, $headers));
            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > self::MAX_ROWS + 1) throw new InvalidArgumentException('Carrier bill row count exceeds the limit.');
                if (! is_array($cells)) continue;
                $row = $this->rowFromCells($line, $cells, $headerMap, $rawHeaders);
                if ($row !== null) $rows[] = $row;
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array{source_row_number:int,raw:array<string,?string>,values:array<string,?string>}> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('Carrier bill XLSX archive is invalid.');
        try {
            $this->assertSafeArchive($zip);
            $sheetPath = $this->sheetPath($zip);
            $sheetXml = $sheetPath ? $zip->getFromName($sheetPath) : false;
            if (! is_string($sheetXml) || $sheetXml === '') throw new InvalidArgumentException('Carrier bill XLSX first worksheet is missing.');

            $headerCells = $this->firstRowCells($sheetXml);
            if ($headerCells === []) throw new InvalidArgumentException('Carrier bill XLSX first worksheet is empty.');
            if (count($headerCells) > self::MAX_COLUMNS) throw new InvalidArgumentException('Carrier bill XLSX has too many columns.');
            $headerShared = $this->sharedStrings($zip, $this->sharedIndexes($headerCells));
            $headers = [];
            foreach ($headerCells as $column => $cell) $headers[$column] = $this->resolveCell($cell, $headerShared);
            [$headerMap, $rawHeaders] = $this->headerMap($headers);

            $rawRows = $this->worksheetRows($sheetXml, array_fill_keys(array_keys($rawHeaders), true));
            $allCells = [];
            foreach ($rawRows as $row) foreach ($row['cells'] as $cell) $allCells[] = $cell;
            $shared = $this->sharedStrings($zip, $this->sharedIndexes($allCells));
            $out = [];
            foreach ($rawRows as $row) {
                $cells = [];
                foreach ($row['cells'] as $column => $cell) $cells[$column] = $this->resolveCell($cell, $shared);
                $parsed = $this->rowFromCells($row['row_number'], $cells, $headerMap, $rawHeaders, true);
                if ($parsed !== null) $out[] = $parsed;
            }
            return $out;
        } finally {
            $zip->close();
        }
    }

    /** @param array<int,string> $headers @return array{0:array<string,int>,1:array<int,string>} */
    private function headerMap(array $headers): array
    {
        $canonicalToColumn = [];
        $rawHeaders = [];
        foreach ($headers as $column => $header) {
            $raw = trim($this->stripBom((string) $header));
            if ($raw === '') continue;
            $canonical = $this->normalizeHeader($raw);
            if (! in_array($canonical, [...self::REQUIRED, ...self::OPTIONAL], true)) continue;
            if (isset($canonicalToColumn[$canonical])) throw new InvalidArgumentException('Carrier bill contains duplicate canonical columns.');
            $canonicalToColumn[$canonical] = (int) $column;
            $rawHeaders[(int) $column] = $raw;
        }
        foreach (self::REQUIRED as $required) {
            if (! array_key_exists($required, $canonicalToColumn)) {
                throw new InvalidArgumentException('Carrier bill is missing required canonical column: '.$required);
            }
        }
        return [$canonicalToColumn, $rawHeaders];
    }

    /** @param array<int,mixed> $cells @param array<string,int> $headerMap @param array<int,string> $rawHeaders */
    private function rowFromCells(int $rowNumber, array $cells, array $headerMap, array $rawHeaders, bool $indexed = false): ?array
    {
        $raw = [];
        foreach ($rawHeaders as $column => $header) {
            $value = $cells[$column] ?? null;
            $raw[$header] = $this->nullableString($value);
        }
        if (count(array_filter($raw, static fn ($v): bool => $v !== null)) === 0) return null;

        $values = array_fill_keys([...self::REQUIRED, ...self::OPTIONAL], null);
        foreach ($headerMap as $canonical => $column) {
            $values[$canonical] = $this->nullableString($cells[$column] ?? null);
        }
        return ['source_row_number' => $rowNumber, 'raw' => $raw, 'values' => $values];
    }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) throw new InvalidArgumentException('Carrier bill XLSX ZIP entry count exceeds the limit.');
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) {
                throw new InvalidArgumentException('Carrier bill XLSX contains an unsafe archive path.');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED) throw new InvalidArgumentException('Carrier bill XLSX uncompressed size exceeds the limit.');
        }
    }

    private function sheetPath(ZipArchive $zip): ?string
    {
        $workbook = $this->xml($zip->getFromName('xl/workbook.xml'));
        $rels = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        if (! $workbook || ! $rels) return null;
        $xp = new DOMXPath($workbook);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheet = $xp->query('//m:sheets/m:sheet[1]')->item(0);
        $id = $sheet?->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if (! $id) return null;
        $rxp = new DOMXPath($rels);
        $rxp->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rxp->query('//p:Relationship') as $rel) {
            if ($rel->getAttribute('Id') !== $id || $rel->getAttribute('TargetMode') === 'External') continue;
            $target = ltrim(str_replace('\\', '/', $rel->getAttribute('Target')), '/');
            if ($target === '' || str_contains($target, '..')) return null;
            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }
        return null;
    }

    /** @return array<int,array{type:string,value:?string}> */
    private function firstRowCells(string $sheetXml): array
    {
        $reader = $this->reader($sheetXml);
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
                return $this->cellsFromRow($reader->readOuterXML(), null);
            }
        } finally { $reader->close(); }
        return [];
    }

    /** @param array<int,true> $columns @return list<array{row_number:int,cells:array<int,array{type:string,value:?string}>}> */
    private function worksheetRows(string $sheetXml, array $columns): array
    {
        $reader = $this->reader($sheetXml); $rows = []; $first = true; $fallback = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
                $fallback++;
                $rowNumber = (int) ($reader->getAttribute('r') ?: $fallback);
                $xml = $reader->readOuterXML();
                if ($first) { $first = false; continue; }
                if (count($rows) >= self::MAX_ROWS) throw new InvalidArgumentException('Carrier bill row count exceeds the limit.');
                $cells = $this->cellsFromRow($xml, $columns);
                if ($cells !== []) $rows[] = ['row_number' => $rowNumber, 'cells' => $cells];
            }
        } finally { $reader->close(); }
        return $rows;
    }

    /** @param array<int,true>|null $columns @return array<int,array{type:string,value:?string}> */
    private function cellsFromRow(string $rowXml, ?array $columns): array
    {
        $doc = $this->xml($rowXml); if (! $doc) return [];
        $xp = new DOMXPath($doc); $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = [];
        foreach ($xp->query('//m:c') as $cell) {
            $column = $this->columnIndex((string) $cell->getAttribute('r'));
            if ($column === null || ($columns !== null && ! isset($columns[$column]))) continue;
            $type = (string) $cell->getAttribute('t');
            if ($type === 'inlineStr') {
                $text = ''; foreach ($xp->query('./m:is//m:t', $cell) as $node) $text .= $node->textContent;
                $cells[$column] = ['type' => 'inline', 'value' => $text];
            } else {
                $cells[$column] = ['type' => $type === 's' ? 'shared' : 'value', 'value' => $xp->query('./m:v', $cell)->item(0)?->textContent];
            }
        }
        return $cells;
    }

    /** @param array<int,array{type:string,value:?string}> $cells @return list<int> */
    private function sharedIndexes(array $cells): array
    {
        $indexes = [];
        foreach ($cells as $cell) if (($cell['type'] ?? '') === 'shared' && is_numeric($cell['value'] ?? null)) $indexes[] = (int) $cell['value'];
        return array_values(array_unique($indexes));
    }

    /** @param list<int> $wanted @return array<int,string> */
    private function sharedStrings(ZipArchive $zip, array $wanted): array
    {
        if ($wanted === []) return [];
        $xml = $zip->getFromName('xl/sharedStrings.xml'); if (! is_string($xml) || $xml === '') return [];
        $want = array_fill_keys($wanted, true); $reader = $this->reader($xml); $result = []; $index = -1;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') continue;
                $index++;
                if (! isset($want[$index])) continue;
                $doc = $this->xml($reader->readOuterXML()); if (! $doc) continue;
                $xp = new DOMXPath($doc); $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $text = ''; foreach ($xp->query('//m:t') as $node) $text .= $node->textContent;
                $result[$index] = $text;
                if (count($result) === count($want)) break;
            }
        } finally { $reader->close(); }
        return $result;
    }

    /** @param array{type:string,value:?string} $cell */
    private function resolveCell(array $cell, array $shared): string
    {
        return $cell['type'] === 'shared' ? ($shared[(int) ($cell['value'] ?? -1)] ?? '') : (string) ($cell['value'] ?? '');
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($this->stripBom($value)));
        $value = preg_replace('/[\s\-]+/u', '_', $value) ?? $value;
        return trim($value, '_');
    }

    private function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)\d+$/i', $reference, $m)) return null;
        $index = 0; foreach (str_split(strtoupper($m[1])) as $letter) $index = $index * 26 + ord($letter) - 64;
        return $index - 1;
    }

    private function reader(string $xml): XMLReader
    {
        $reader = new XMLReader();
        if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) throw new InvalidArgumentException('Carrier bill XLSX XML is invalid.');
        return $reader;
    }

    private function xml(string|false $value): ?DOMDocument
    {
        if (! is_string($value) || $value === '') return null;
        $doc = new DOMDocument(); $old = libxml_use_internal_errors(true);
        try { return $doc->loadXML($value, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ? $doc : null; }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }
}
