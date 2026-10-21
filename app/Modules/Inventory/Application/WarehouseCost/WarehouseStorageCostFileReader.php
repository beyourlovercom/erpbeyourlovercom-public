<?php

namespace App\Modules\Inventory\Application\WarehouseCost;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class WarehouseStorageCostFileReader
{
    public const PARSER_VERSION = 'warehouse-storage-cost-v1';
    private const REQUIRED = ['warehouse_slug','billing_period_start','billing_period_end','storage_pool_amount','currency','reference_number','tracking_number','outbound_units'];
    private const SUPPORTED_CURRENCIES = ['USD','EUR','CNY','CAD','GBP','AUD','JPY','HKD','SGD','PHP'];

    /** @return array{batch:array<string,string>,rows:list<array{source_row_number:int,raw:array<string,string>,values:array<string,string>}>} */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) throw new InvalidArgumentException('Warehouse storage source is unreadable.');
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') throw new InvalidArgumentException('Warehouse storage source must be canonical CSV.');
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new InvalidArgumentException('Warehouse storage source cannot be opened.');
        try {
            $header = fgetcsv($handle);
            if ($header === false) throw new InvalidArgumentException('Warehouse storage source is empty.');
            $header = array_map(fn ($v): string => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $v) ?? ''), $header);
            if ($header !== self::REQUIRED) throw new InvalidArgumentException('Warehouse storage source headers are invalid.');
            $rows = []; $batch = null; $line = 1; $seenIdentity = [];
            while (($data = fgetcsv($handle)) !== false) {
                $line++;
                if (count($data) === 1 && trim((string) $data[0]) === '') continue;
                if (count($data) !== count($header)) throw new InvalidArgumentException('Warehouse storage source row width is invalid at line '.$line.'.');
                $raw = array_combine($header, array_map(fn ($v): string => trim((string) $v), $data));
                $values = $this->normalize($raw, $line);
                $identity = strtoupper($values['reference_number']).'|'.$values['tracking_number'];
                if (isset($seenIdentity[$identity])) {
                    throw new InvalidArgumentException('Warehouse storage source contains duplicate outbound identity at line '.$line.'.');
                }
                $seenIdentity[$identity] = true;
                $current = array_intersect_key($values, array_flip(['warehouse_slug','billing_period_start','billing_period_end','storage_pool_amount','currency']));
                if ($batch === null) $batch = $current;
                elseif ($batch !== $current) throw new InvalidArgumentException('Warehouse storage batch metadata is inconsistent at line '.$line.'.');
                $rows[] = ['source_row_number'=>$line, 'raw'=>$raw, 'values'=>$values];
            }
            if ($rows === [] || $batch === null) throw new InvalidArgumentException('Warehouse storage source has no outbound rows.');
            return ['batch'=>$batch, 'rows'=>$rows];
        } finally { fclose($handle); }
    }

    /** @param array<string,string> $raw @return array<string,string> */
    private function normalize(array $raw, int $line): array
    {
        $slug = strtolower(trim($raw['warehouse_slug']));
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug) !== 1) throw new InvalidArgumentException('warehouse_slug invalid at line '.$line.'.');
        $start = $this->date($raw['billing_period_start'], $line); $end = $this->date($raw['billing_period_end'], $line);
        if ($start > $end) throw new InvalidArgumentException('billing period invalid at line '.$line.'.');
        $pool = $this->money4($raw['storage_pool_amount'], $line);
        if (bccomp($pool, '0', 4) <= 0) throw new InvalidArgumentException('storage_pool_amount must be positive at line '.$line.'.');
        $currency = strtoupper(trim($raw['currency']));
        if (! in_array($currency, self::SUPPORTED_CURRENCIES, true)) throw new InvalidArgumentException('currency unsupported at line '.$line.'.');
        $units = trim($raw['outbound_units']);
        if (preg_match('/^[1-9][0-9]*$/D', $units) !== 1) throw new InvalidArgumentException('outbound_units invalid at line '.$line.'.');
        $reference = trim($raw['reference_number']); $tracking = strtoupper(trim($raw['tracking_number']));
        if ($reference === '' && $tracking === '') throw new InvalidArgumentException('reference_number and tracking_number cannot both be blank at line '.$line.'.');
        if (strlen($reference) > 128 || strlen($tracking) > 191) throw new InvalidArgumentException('row identity too long at line '.$line.'.');
        return ['warehouse_slug'=>$slug,'billing_period_start'=>$start,'billing_period_end'=>$end,'storage_pool_amount'=>$pool,'currency'=>$currency,
            'reference_number'=>$reference,'tracking_number'=>$tracking,'outbound_units'=>$units];
    }

    private function date(string $value, int $line): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) throw new InvalidArgumentException('date invalid at line '.$line.'.');
        try { return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC')->toDateString(); }
        catch (\Throwable) { throw new InvalidArgumentException('date invalid at line '.$line.'.'); }
    }

    private function money4(string $value, int $line): string
    {
        $value = trim($value);
        if (preg_match('/^\d{1,12}(?:\.\d{1,4})?$/D', $value) !== 1) throw new InvalidArgumentException('money invalid at line '.$line.'.');
        return bcadd($value, '0', 4);
    }
}
