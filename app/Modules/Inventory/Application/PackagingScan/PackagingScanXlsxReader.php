<?php

namespace App\Modules\Inventory\Application\PackagingScan;

use DOMDocument;
use DOMXPath;
use XMLReader;
use ZipArchive;

final class PackagingScanXlsxReader
{
    private const MAX_ENTRIES = 240;
    private const MAX_UNCOMPRESSED = 96 * 1024 * 1024;

    private const ALIASES = [
        'order_reference' => ['客户参考单号', '客户参考单号 customer reference number', 'customer reference number', 'customer reference no', '订单号', 'order number', 'external order number', 'erp订单号', '前台订单号'],
        'tracking_number' => ['运单号', '运单号 tracking number', 'tracking number', 'tracking no', 'tracking no.', '追踪号', '物流单号'],
        'servicer_reference' => ['服务商单号', 'servicer', 'servicer reference', '服务商参考号'],
        'forecast_weight_kg' => ['预报重量', '预报重量 forecast weight', 'forecast weight', 'forecast weight kg'],
        'inbound_weight_kg' => ['签入重量', '签入重量 inbound weight', 'inbound weight', 'actual weight', '实际重量', '称重', '实重'],
        'volume_weight_kg' => ['体积重量', '体积重量 volume weight', 'volume weight', 'volumetric weight'],
        'billing_weight_kg' => ['计费重量', '计费重量 billing weight', 'billing weight', 'chargeable weight'],
        'weight_difference_kg' => ['重量差异', '重量差异 weight difference', 'weight difference'],
        'length_cm' => ['长', '长 length', 'length', '长度', 'length cm'],
        'width_cm' => ['宽', '宽 width', 'width', '宽度', 'width cm'],
        'height_cm' => ['高', '高 height', 'height', '高度', 'height cm'],
        'measured_at' => ['签入时间', 'scan time', 'measured at', 'measurement time', '称重时间', '操作时间'],
    ];

    /** @return list<array<string,mixed>> */
    public function parsePath(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException('XLSX 文件不可读取。');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \InvalidArgumentException('XLSX 文件无效。');
        try {
            $this->assertSafeArchive($zip);
            $sheetPath = $this->sheetPath($zip);
            $sheetXml = $sheetPath ? $zip->getFromName($sheetPath) : false;
            if (! is_string($sheetXml) || $sheetXml === '') throw new \InvalidArgumentException('XLSX 第一工作表不存在。');
            $headerRaw = $this->firstRowCells($sheetXml);
            if ($headerRaw === []) throw new \InvalidArgumentException('XLSX 第一工作表为空。');
            $headerShared = $this->sharedStrings($zip, $this->sharedIndexes($headerRaw));
            $columns = [];
            foreach ($headerRaw as $column => $raw) {
                $header = $this->normalizeHeader($this->resolveCell($raw, $headerShared));
                foreach (self::ALIASES as $key => $aliases) {
                    if (in_array($header, $aliases, true)) { $columns[$key] ??= $column; break; }
                }
            }
            if (! isset($columns['order_reference'])) throw new \InvalidArgumentException('XLSX 缺少客户参考单号 / Order Number 列。');
            $rawRows = $this->safeRows($sheetXml, array_flip(array_values($columns)));
            $flat = [];
            foreach ($rawRows as $row) foreach ($row['cells'] as $cell) $flat[] = $cell;
            $shared = $this->sharedStrings($zip, $this->sharedIndexes($flat));
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
            if (count(array_filter($safe, fn ($v) => $v !== null)) === 0) continue;
            $out[] = ['source_row_number' => $row['row_number'], ...$safe];
        }
        return $out;
    }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) throw new \InvalidArgumentException('XLSX ZIP 条目超限。');
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_starts_with($name, '/') || in_array('..', explode('/', $name), true)) throw new \InvalidArgumentException('XLSX 包含非法路径。');
            $size += (int) ($stat['size'] ?? 0);
            if ($size > self::MAX_UNCOMPRESSED) throw new \InvalidArgumentException('XLSX 解压大小超限。');
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

    private function firstRowCells(string $sheetXml): array
    {
        $reader = $this->reader($sheetXml); $cells = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
                $cells = $this->cellsFromRow($reader->readOuterXML(), null); break;
            }
        } finally { $reader->close(); }
        return $cells;
    }

    private function safeRows(string $sheetXml, array $safeColumns): array
    {
        $reader = $this->reader($sheetXml); $rows = []; $header = false; $fallback = 0;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
                $fallback++; $rowNumber = (int) ($reader->getAttribute('r') ?: $fallback);
                $xml = $reader->readOuterXML();
                if (! $header) { $header = true; continue; }
                $cells = $this->cellsFromRow($xml, $safeColumns);
                if ($cells !== []) $rows[] = ['row_number'=>$rowNumber,'cells'=>$cells];
            }
        } finally { $reader->close(); }
        return $rows;
    }

    private function cellsFromRow(string $rowXml, ?array $safeColumns): array
    {
        $doc = $this->xml($rowXml); if (! $doc) return [];
        $xp = new DOMXPath($doc); $xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = [];
        foreach ($xp->query('//m:c') as $cell) {
            $column = $this->columnIndex((string) $cell->getAttribute('r'));
            if ($column === null || ($safeColumns !== null && ! isset($safeColumns[$column]))) continue;
            $type = (string) $cell->getAttribute('t');
            if ($type === 'inlineStr') {
                $text=''; foreach ($xp->query('./m:is//m:t',$cell) as $node) $text .= $node->textContent;
                $cells[$column] = ['type'=>'inline','value'=>$text];
            } else {
                $cells[$column] = ['type'=>$type === 's' ? 'shared' : 'value','value'=>$xp->query('./m:v',$cell)->item(0)?->textContent];
            }
        }
        return $cells;
    }

    private function sharedIndexes(array $cells): array
    {
        $indexes=[]; foreach ($cells as $cell) if (($cell['type'] ?? '') === 'shared' && is_numeric($cell['value'] ?? null)) $indexes[]=(int)$cell['value'];
        return array_values(array_unique($indexes));
    }

    private function sharedStrings(ZipArchive $zip, array $wanted): array
    {
        if ($wanted === []) return [];
        $xml=$zip->getFromName('xl/sharedStrings.xml'); if (!is_string($xml)||$xml==='') return [];
        $want=array_fill_keys($wanted,true); $reader=$this->reader($xml); $result=[]; $index=-1;
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') continue;
                $index++; if (!isset($want[$index])) continue;
                $doc=$this->xml($reader->readOuterXML()); if (!$doc) continue;
                $xp=new DOMXPath($doc); $xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $text=''; foreach ($xp->query('//m:t') as $node) $text.=$node->textContent;
                $result[$index]=$text; if (count($result)===count($want)) break;
            }
        } finally { $reader->close(); }
        return $result;
    }

    private function resolveCell(array $cell, array $shared): string
    { return $cell['type']==='shared' ? ($shared[(int)($cell['value']??-1)] ?? '') : (string)($cell['value']??''); }
    private function normalizeHeader(string $value): string
    { return mb_strtolower(preg_replace('/\s+/u',' ',trim($value)) ?? trim($value)); }
    private function value(string $value): ?string { $value=trim($value); return $value===''?null:$value; }
    private function columnIndex(string $reference): ?int
    {
        if (!preg_match('/^([A-Z]+)\d+$/i',$reference,$m)) return null;
        $index=0; foreach(str_split(strtoupper($m[1])) as $letter) $index=$index*26+ord($letter)-64;
        return $index-1;
    }
    private function reader(string $xml): XMLReader
    { $r=new XMLReader(); if(!$r->XML($xml,null,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) throw new \InvalidArgumentException('XLSX XML 无效。'); return $r; }
    private function xml(string|false $value): ?DOMDocument
    {
        if(!is_string($value)||$value==='') return null; $doc=new DOMDocument(); $old=libxml_use_internal_errors(true);
        try { return $doc->loadXML($value,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)?$doc:null; }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }
}
