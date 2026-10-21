<?php

namespace App\Modules\Logistics\Application\HistoricalImport;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use XMLReader;
use ZipArchive;

/** Reads and retains only the explicitly approved logistics-safe worksheet columns. */
final class HistoricalShipmentXlsxReader
{
    private const MAX_ENTRIES = 200;
    private const MAX_UNCOMPRESSED = 64 * 1024 * 1024;
    private const ALIASES = [
        'status' => ['status 进度', 'status', '状态'],
        'warehouse' => ['stock 仓库', 'stock/warehouse', 'stock / warehouse', 'warehouse', '仓库', '库存/仓库'],
        'carrier' => ['代理 carrier', 'carrier', '物流商', '承运商'],
        'tracking' => ['追踪单号 tracking no.', '追踪单号 tracking no', 'tracking no.', 'tracking no', 'tracking', '物流单号', '追踪号'],
        'input_time' => ['input time', '录入时间', '输入时间'],
        'order_no' => ['单号 order no.', '单号 order no', 'order no.', 'order no', 'order number', '订单号'],
        'sku' => ['sku', 'sku编码', '商品sku'],
        'quantity' => ['数量quantity', 'quantity', 'qty', '数量'],
    ];

    /** @return array<int, array{source_row_number:int,status:?string,warehouse:?string,carrier:?string,tracking:?string,input_time:?string,order_no:?string,sku:?string,quantity:?string}> */
    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') abort(422, 'XLSX 文件不可读取。');

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) abort(422, 'XLSX 文件无效。');
        try {
            $this->assertSafeArchive($zip);
            $sheetPath = $this->sheetPath($zip);
            $sheetXml = $sheetPath ? $zip->getFromName($sheetPath) : false;
            if (! is_string($sheetXml) || $sheetXml === '') abort(422, 'XLSX 第一工作表不存在。');

            $headerRaw = $this->firstRowCells($sheetXml);
            if ($headerRaw === []) abort(422, 'XLSX 第一工作表为空或格式无效。');
            $headerShared = $this->sharedStrings($zip, $this->sharedIndexes($headerRaw));
            $columns = [];
            foreach ($headerRaw as $column => $raw) {
                $header = $this->normalizeHeader($this->resolveCell($raw, $headerShared));
                foreach (self::ALIASES as $key => $aliases) {
                    if (in_array($header, $aliases, true)) { $columns[$key] ??= $column; break; }
                }
            }
            if (! isset($columns['order_no'], $columns['tracking'])) abort(422, 'XLSX 缺少 Order No. 或 Tracking No. 安全列。');

            $rawRows = $this->safeRows($sheetXml, array_flip(array_values($columns)));
            $shared = $this->sharedStrings($zip, $this->sharedIndexes(array_merge(...array_map(fn (array $row): array => $row['cells'], $rawRows ?: [['cells' => []]]))));
        } finally {
            $zip->close();
        }

        $columnToKey = array_flip($columns);
        $out = [];
        foreach ($rawRows as $row) {
            $safe = array_fill_keys(array_keys(self::ALIASES), null);
            foreach ($row['cells'] as $column => $raw) {
                if (! isset($columnToKey[$column])) continue;
                $safe[$columnToKey[$column]] = $this->value($this->resolveCell($raw, $shared));
            }
            if (count(array_filter($safe, fn ($value): bool => $value !== null)) === 0) continue;
            $out[] = ['source_row_number' => $row['row_number'], ...$safe];
        }
        return $out;
    }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) abort(422, 'XLSX ZIP 条目超限。');
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) abort(422, 'XLSX 包含非法路径。');
            $size += (int) ($stat['size'] ?? 0);
            if ($size > self::MAX_UNCOMPRESSED) abort(422, 'XLSX 解压大小超限。');
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
            if ($rel->getAttribute('Id') !== $id) continue;
            if ($rel->getAttribute('TargetMode') === 'External') return null;
            $target = ltrim(str_replace('\\', '/', $rel->getAttribute('Target')), '/');
            if ($target === '' || str_contains($target, '..')) return null;
            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }
        return null;
    }

    /** @return array<int,array{type:string,value:?string}> */
    private function firstRowCells(string $sheetXml): array
    {
        $reader = $this->reader($sheetXml); $cells = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
                $rowXml = $reader->readOuterXML();
                $cells = $this->cellsFromRow($rowXml, null);
                break;
            }
        } finally { $reader->close(); }
        return $cells;
    }

    /** @param array<int,true> $safeColumns @return array<int,array{row_number:int,cells:array<int,array{type:string,value:?string}>}> */
    private function safeRows(string $sheetXml, array $safeColumns): array
    {
        $reader = $this->reader($sheetXml); $rows = []; $seenHeader = false; $fallback = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
                $fallback++;
                $rowNumber = (int) ($reader->getAttribute('r') ?: $fallback);
                $rowXml = $reader->readOuterXML();
                if (! $seenHeader) { $seenHeader = true; continue; }
                $cells = $this->cellsFromRow($rowXml, $safeColumns);
                if ($cells !== []) $rows[] = ['row_number' => $rowNumber, 'cells' => $cells];
            }
        } finally { $reader->close(); }
        return $rows;
    }

    /** @param array<int,true>|null $safeColumns @return array<int,array{type:string,value:?string}> */
    private function cellsFromRow(string $rowXml, ?array $safeColumns): array
    {
        $doc = $this->xml($rowXml); if (! $doc) return [];
        $xp = new DOMXPath($doc); $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = [];
        foreach ($xp->query('//m:c') as $cell) {
            $column = $this->columnIndex((string) $cell->getAttribute('r'));
            if ($column === null || ($safeColumns !== null && ! isset($safeColumns[$column]))) continue;
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

    /** @param array<int,array{type:string,value:?string}> $cells @return int[] */
    private function sharedIndexes(array $cells): array
    {
        $indexes = [];
        foreach ($cells as $cell) if (($cell['type'] ?? '') === 'shared' && is_numeric($cell['value'] ?? null)) $indexes[] = (int) $cell['value'];
        return array_values(array_unique($indexes));
    }

    /** @param int[] $wanted @return array<int,string> */
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
        if ($cell['type'] === 'shared') return $shared[(int) ($cell['value'] ?? -1)] ?? '';
        return (string) ($cell['value'] ?? '');
    }

    private function normalizeHeader(string $value): string
    { return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value)); }
    private function value(string $value): ?string { $value = trim($value); return $value === '' ? null : $value; }
    private function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)\d+$/i', $reference, $m)) return null;
        $index = 0; foreach (str_split(strtoupper($m[1])) as $letter) $index = $index * 26 + ord($letter) - 64;
        return $index - 1;
    }
    private function reader(string $xml): XMLReader
    { $reader = new XMLReader(); if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) abort(422, 'XLSX XML 无效。'); return $reader; }
    private function xml(string|false $value): ?DOMDocument
    { if (! is_string($value) || $value === '') return null; $doc = new DOMDocument(); $old = libxml_use_internal_errors(true); try { return $doc->loadXML($value, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ? $doc : null; } finally { libxml_clear_errors(); libxml_use_internal_errors($old); } }
}
