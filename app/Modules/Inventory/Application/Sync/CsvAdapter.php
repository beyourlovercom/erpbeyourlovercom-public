<?php

namespace App\Modules\Inventory\Application\Sync;

use Illuminate\Http\UploadedFile;

final class CsvAdapter
{
    /** @return NormalizedRow[] */
    public function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) return [];

        $rawHeaders = fgetcsv($handle);
        if ($rawHeaders === false || $rawHeaders === null) { fclose($handle); return []; }

        $headers = array_map('trim', $rawHeaders);
        $colMap  = $this->mapHeaders($headers);

        if (!isset($colMap['sku']) || !isset($colMap['quantity'])) { fclose($handle); return []; }

        $rows = [];
        $rowNo = 2;
        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === null) continue;
            $cells = array_map('trim', $cells);
            $get   = fn (string $key) => isset($colMap[$key]) ? ($cells[$colMap[$key]] ?? null) : null;

            $rawSku     = $get('sku');
            $rawQty     = $get('quantity');
            $rawStatus  = $get('stock_status');
            $rawCnt     = $get('stock_count_at');
            $rawPutaway = $get('putaway_date');

            if ($rawSku === null && $rawQty === null) { $rowNo++; continue; }

            $rows[] = new NormalizedRow(
                sourceRowNo:   $rowNo,
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
            $rowNo++;
        }
        fclose($handle);
        return $rows;
    }

    private function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $idx => $h) {
            $norm = mb_strtolower(trim($h));
            match (true) {
                str_contains($norm, 'sku')                                         => $map['sku']           = $idx,
                str_contains($norm, 'qty') || str_contains($norm, '数量')          => $map['quantity']      = $idx,
                str_contains($norm, 'stock prop') || str_contains($norm, '库存属性')=> $map['stock_status']  = $idx,
                str_contains($norm, 'stock count') || str_contains($norm, '库存统计')=>$map['stock_count_at']= $idx,
                str_contains($norm, 'putaway') || str_contains($norm, '上架')       => $map['putaway_date']  = $idx,
                str_contains($norm, 'product') || str_contains($norm, '产品')       => $map['product_name']  = $idx,
                str_contains($norm, 'warehouse') || str_contains($norm, '仓库')     => $map['warehouse']     = $idx,
                str_contains($norm, 'dimension') || str_contains($norm, '尺寸')     => $map['dimension']     = $idx,
                str_contains($norm, 'volume') || str_contains($norm, '体积')        => $map['total_volume']  = $idx,
                default => null,
            };
        }
        return $map;
    }
}
