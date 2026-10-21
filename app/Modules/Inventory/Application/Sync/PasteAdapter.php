<?php

namespace App\Modules\Inventory\Application\Sync;

/**
 * Parses tab-separated paste input (copy from spreadsheet).
 * Minimum required columns: SKU, Qty. All other known columns are mapped if present.
 * A selected target warehouse is mandatory; raw warehouse column is reference only.
 */
final class PasteAdapter
{
    /** @return NormalizedRow[] */
    public function parse(string $text): array
    {
        $lines = preg_split('/\r?\n/', trim($text));
        if (count($lines) < 2) return [];

        $headers = array_map('trim', explode("\t", array_shift($lines)));
        $colMap   = $this->mapHeaders($headers);

        if (!isset($colMap['sku']) || !isset($colMap['quantity'])) return [];

        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') continue;
            $cells = array_map('trim', explode("\t", $line));
            $get   = fn (string $key) => isset($colMap[$key]) ? ($cells[$colMap[$key]] ?? null) : null;

            $rawSku    = $get('sku');
            $rawQty    = $get('quantity');
            $rawStatus = $get('stock_status');
            $rawCnt    = $get('stock_count_at');
            $rawPutaway= $get('putaway_date');

            $rows[] = new NormalizedRow(
                sourceRowNo:   $i + 2, // 1-based, row 1 is header
                sku:           RowNormalizer::normalizeSku($rawSku),
                stockStatus:   ($rawStatus === null || trim((string) $rawStatus) === '') ? 'sellable' : RowNormalizer::normalizeStockStatus((string) $rawStatus),
                stockCountAt:  RowNormalizer::normalizeDate($rawCnt),
                putawayDate:   RowNormalizer::normalizeDate($rawPutaway),
                quantity:      RowNormalizer::normalizeQuantity($rawQty),
                rawProductName:   $get('product_name'),
                rawWarehouseName: $get('warehouse'),
                rawDimension:     $get('dimension'),
                rawTotalVolume:   $get('total_volume'),
                rawStockStatus:   $rawStatus,
                rawStockCountDate: $rawCnt !== null ? (string) $rawCnt : null,
                rawPutawayDate:    $rawPutaway !== null ? (string) $rawPutaway : null,
                rawQuantity:       $rawQty !== null ? (string) $rawQty : null,
            );
        }
        return $rows;
    }

    private function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $idx => $h) {
            $norm = mb_strtolower(trim($h));
            match (true) {
                str_contains($norm, 'sku')          => $map['sku']           = $idx,
                str_contains($norm, 'qty') || str_contains($norm, '数量')
                                                    => $map['quantity']      = $idx,
                str_contains($norm, 'stock prop') || str_contains($norm, '库存属性')
                                                    => $map['stock_status']  = $idx,
                str_contains($norm, 'stock count') || str_contains($norm, '库存统计')
                                                    => $map['stock_count_at']= $idx,
                str_contains($norm, 'putaway') || str_contains($norm, '上架')
                                                    => $map['putaway_date']  = $idx,
                str_contains($norm, 'product') || str_contains($norm, '产品')
                                                    => $map['product_name']  = $idx,
                str_contains($norm, 'warehouse') || str_contains($norm, '仓库')
                                                    => $map['warehouse']     = $idx,
                str_contains($norm, 'dimension') || str_contains($norm, '尺寸')
                                                    => $map['dimension']     = $idx,
                str_contains($norm, 'volume') || str_contains($norm, '体积')
                                                    => $map['total_volume']  = $idx,
                default => null,
            };
        }
        return $map;
    }
}
