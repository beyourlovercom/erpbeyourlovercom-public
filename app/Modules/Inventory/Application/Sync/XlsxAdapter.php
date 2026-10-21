<?php

namespace App\Modules\Inventory\Application\Sync;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use ZipArchive;

final class XlsxAdapter
{
    private const MAX_ZIP_ENTRIES = 200;
    private const MAX_UNCOMPRESSED_BYTES = 25 * 1024 * 1024;

    /** @return NormalizedRow[] */
    public function parse(UploadedFile $file): array
    {
        $rows = $this->readFirstWorksheet($file->getRealPath());
        if (count($rows) < 2) return [];

        $headers = array_map(fn ($v) => trim((string) $v), $rows[0]);
        $colMap = $this->mapHeaders($headers);

        $hasSplitQoH = isset($colMap['standard_qoh']);
        if (!isset($colMap['sku']) || (!isset($colMap['quantity']) && !$hasSplitQoH)) return [];

        $result = [];
        foreach (array_slice($rows, 1, null, true) as $rowIdx => $cells) {
            $get = fn (string $key) => isset($colMap[$key]) ? ($cells[$colMap[$key]] ?? null) : null;
            $rawSku = trim((string) $get('sku'));

            if ($hasSplitQoH) {
                $rawStdQty = trim((string) $get('standard_qoh'));
                if ($rawSku === '' && $rawStdQty === '') continue;

                $rawCnt    = $get('stock_count_at');
                $rawPutaway = $get('putaway_date');
                $common = [
                    'sourceRowNo'    => $rowIdx + 1,
                    'sku'            => RowNormalizer::normalizeSku($rawSku ?: null),
                    'stockCountAt'   => $this->normalizeExcelDate($rawCnt),
                    'putawayDate'    => $this->normalizeExcelDate($rawPutaway),
                    'rawProductName' => $this->nullableString($get('product_name')),
                    'rawWarehouseName' => $this->nullableString($get('warehouse')),
                    'rawDimension'   => $this->nullableString($get('dimension')),
                    'rawTotalVolume' => $this->nullableString($get('total_volume')),
                    'rawStockStatus' => null,
                    'rawStockCountDate' => $this->nullableString($rawCnt),
                    'rawPutawayDate' => $this->nullableString($rawPutaway),
                ];

                // Sellable row from Standard_QoH
                $result[] = new NormalizedRow(
                    ...$common,
                    stockStatus: 'sellable',
                    quantity: RowNormalizer::normalizeQuantity($rawStdQty ?: null),
                    rawQuantity: $rawStdQty !== '' ? $rawStdQty : null,
                );

                // Defective row from Damage_QoH — suppressed when blank or zero
                if (isset($colMap['damage_qoh'])) {
                    $rawDmgQty = trim((string) $get('damage_qoh'));
                    $dmgQty = RowNormalizer::normalizeQuantity($rawDmgQty ?: null);
                    if ($dmgQty !== null && $dmgQty !== 0) {
                        $result[] = new NormalizedRow(
                            ...$common,
                            stockStatus: 'defective',
                            quantity: $dmgQty,
                            rawQuantity: $rawDmgQty,
                        );
                    }
                }
            } else {
                $rawQty = trim((string) $get('quantity'));
                if ($rawSku === '' && $rawQty === '') continue;

                $rawStatus  = $get('stock_status');
                $rawCnt     = $get('stock_count_at');
                $rawPutaway = $get('putaway_date');

                $result[] = new NormalizedRow(
                    sourceRowNo: $rowIdx + 1,
                    sku: RowNormalizer::normalizeSku($rawSku ?: null),
                    stockStatus: ($rawStatus === null || trim((string) $rawStatus) === '')
                        ? 'sellable'
                        : RowNormalizer::normalizeStockStatus((string) $rawStatus),
                    stockCountAt: $this->normalizeExcelDate($rawCnt),
                    putawayDate: $this->normalizeExcelDate($rawPutaway),
                    quantity: RowNormalizer::normalizeQuantity($rawQty ?: null),
                    rawProductName: $this->nullableString($get('product_name')),
                    rawWarehouseName: $this->nullableString($get('warehouse')),
                    rawDimension: $this->nullableString($get('dimension')),
                    rawTotalVolume: $this->nullableString($get('total_volume')),
                    rawStockStatus: $this->nullableString($rawStatus),
                    rawStockCountDate: $this->nullableString($rawCnt),
                    rawPutawayDate: $this->nullableString($rawPutaway),
                    rawQuantity: $rawQty !== '' ? $rawQty : null,
                );
            }
        }

        return $result;
    }

    /** @return array<int, array<int, string|int|float|null>> */
    private function readFirstWorksheet(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return [];

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) return [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_UNCOMPRESSED_BYTES) return [];
            }

            $sheetPath = $this->firstWorksheetPath($zip);
            if ($sheetPath === null) return [];

            $sharedStrings = $this->sharedStrings($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) return [];

            return $this->sheetRows($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    private function firstWorksheetPath(ZipArchive $zip): ?string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) return null;

        $workbook = $this->loadXml($workbookXml);
        $rels = $this->loadXml($relsXml);
        if ($workbook === null || $rels === null) return null;

        $xp = new DOMXPath($workbook);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheet = $xp->query('//m:sheets/m:sheet[1]')->item(0);
        if ($sheet === null) return null;
        $relId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if ($relId === '') return null;

        $rxp = new DOMXPath($rels);
        $rxp->registerNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationship = $rxp->query('//p:Relationship[@Id="'.$relId.'"]')->item(0);
        if ($relationship === null) return null;
        $target = $relationship->attributes?->getNamedItem('Target')?->nodeValue;
        if ($target === null || $target === '') return null;

        $target = str_replace('\\', '/', $target);
        $target = ltrim($target, '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $doc = $this->loadXml($xml);
        if ($doc === null) return [];

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $result = [];
        foreach ($xp->query('//m:si') as $si) {
            $parts = [];
            foreach ($xp->query('.//m:t', $si) as $textNode) $parts[] = $textNode->textContent;
            $result[] = implode('', $parts);
        }
        return $result;
    }

    /** @return array<int, array<int, string|int|float|null>> */
    private function sheetRows(string $xml, array $sharedStrings): array
    {
        $doc = $this->loadXml($xml);
        if ($doc === null) return [];
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        foreach ($xp->query('//m:sheetData/m:row') as $rowNode) {
            $cells = [];
            foreach ($xp->query('./m:c', $rowNode) as $cell) {
                $ref = $cell->attributes?->getNamedItem('r')?->nodeValue ?? '';
                if (!preg_match('/^([A-Z]+)\d+$/i', $ref, $m)) continue;
                $idx = $this->columnIndex(strtoupper($m[1]));
                $type = $cell->attributes?->getNamedItem('t')?->nodeValue;
                $valueNode = $xp->query('./m:v', $cell)->item(0);
                $value = $valueNode?->textContent;

                if ($type === 's') {
                    $cells[$idx] = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($xp->query('./m:is//m:t', $cell) as $textNode) $parts[] = $textNode->textContent;
                    $cells[$idx] = implode('', $parts);
                } elseif ($value === null) {
                    $cells[$idx] = null;
                } elseif (is_numeric($value)) {
                    $cells[$idx] = str_contains($value, '.') ? (float) $value : (int) $value;
                } else {
                    $cells[$idx] = $value;
                }
            }
            if ($cells === []) continue;
            $max = max(array_keys($cells));
            $rows[] = array_map(fn ($i) => $cells[$i] ?? null, range(0, $max));
        }
        return $rows;
    }

    private function loadXml(string $xml): ?DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            return $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ? $doc : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) $index = ($index * 26) + (ord($letter) - 64);
        return $index - 1;
    }

    private function normalizeExcelDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        if (is_numeric($raw)) {
            $days = (int) floor((float) $raw);
            return (new \DateTimeImmutable('1899-12-30'))->modify("+{$days} days")->format('Y-m-d');
        }
        return RowNormalizer::normalizeDate((string) $raw);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $idx => $h) {
            $norm = mb_strtolower(trim($h));
            $raw  = trim($h); // preserve original case for exact-match aliases
            match (true) {
                str_contains($norm, 'sku') => $map['sku'] = $idx,
                // Standard_QoH and Damage_QoH split-mode (checked before generic qty)
                $raw === 'Standard_QoH' || $norm === 'standard_qoh' => $map['standard_qoh'] = $idx,
                $raw === 'Damage_QoH'   || $norm === 'damage_qoh'   => $map['damage_qoh']   = $idx,
                // Generic quantity aliases (warehouse real-data headers)
                str_contains($norm, 'qty') || str_contains($norm, '数量')
                    || str_contains($norm, '在库库存') || str_contains($norm, '当前库存')
                    || str_contains($norm, '库存数量')
                    || $norm === 'quantity' || $norm === 'quantity on hand' || $norm === 'on hand'
                    => $map['quantity'] = $idx,
                str_contains($norm, 'stock prop') || str_contains($norm, '库存属性') => $map['stock_status'] = $idx,
                str_contains($norm, 'stock count') || str_contains($norm, '库存统计') => $map['stock_count_at'] = $idx,
                // Inbound Time accepted as putaway date
                str_contains($norm, 'putaway') || str_contains($norm, '上架')
                    || str_contains($norm, 'inbound time')
                    => $map['putaway_date'] = $idx,
                str_contains($norm, 'product') || str_contains($norm, '产品') => $map['product_name'] = $idx,
                str_contains($norm, 'warehouse') || str_contains($norm, '仓库') => $map['warehouse'] = $idx,
                str_contains($norm, 'dimension') || str_contains($norm, '尺寸') => $map['dimension'] = $idx,
                str_contains($norm, 'volume') || str_contains($norm, '体积') => $map['total_volume'] = $idx,
                default => null,
            };
        }
        return $map;
    }
}
