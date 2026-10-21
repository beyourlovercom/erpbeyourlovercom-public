<?php

namespace App\Modules\Inventory\Application\WarehouseCost;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use XMLReader;
use ZipArchive;

final class MorePlusBillFileReader
{
    public const PARSER_VERSION = 'moreplus-bill-v3';
    private const MAX_FILE_BYTES = 16 * 1024 * 1024;
    private const MAX_ROWS = 50000;
    private const MAX_ZIP_ENTRIES = 240;
    private const MAX_UNCOMPRESSED = 64 * 1024 * 1024;
    private const REQUIRED_SHEETS = ['Invoice','Parcel Outbound','Storage','Shipping'];

    /** @return array<string,mixed> */
    public function read(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new InvalidArgumentException('MorePlus bill is not readable.');
        }
        $size = filesize($real);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('MorePlus bill size is invalid or exceeds the limit.');
        }
        if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new InvalidArgumentException('MorePlus bill must be XLSX.');
        }

        $zip = new ZipArchive;
        if ($zip->open($real) !== true) {
            throw new InvalidArgumentException('MorePlus XLSX archive is invalid.');
        }
        try {
            $this->assertSafeArchive($zip);
            $paths = $this->sheetPaths($zip);
            foreach (self::REQUIRED_SHEETS as $sheet) {
                if (! isset($paths[$sheet])) {
                    throw new InvalidArgumentException('MorePlus bill missing sheet: '.$sheet);
                }
            }
            $shared = $this->sharedStrings($zip);
            $invoiceRows = $this->sheetRows($zip, $paths['Invoice'], $shared);
            $parcelRows = $this->sheetRows($zip, $paths['Parcel Outbound'], $shared);
            $storageRows = $this->sheetRows($zip, $paths['Storage'], $shared);
            $shippingRows = $this->sheetRows($zip, $paths['Shipping'], $shared);

            $invoice = $this->invoice($invoiceRows);
            $parcel = $this->grouped($parcelRows, 'parcel_outbound', 'Parcel Outbound');
            $storage = $this->storage($storageRows);
            $shipping = $this->grouped($shippingRows, 'shipping', 'Shipping');
            $rows = [...$shipping, ...$parcel, ...$storage];

            $totals = [
                'shipping' => $this->sum($shipping),
                'parcel_outbound' => $this->sum($parcel),
                'storage' => $this->sum($storage),
            ];
            foreach ($totals as $category => $amount) {
                if (bccomp($amount, (string) $invoice['totals'][$category], 4) !== 0) {
                    throw new InvalidArgumentException('MorePlus '.$category.' detail total does not match Invoice summary.');
                }
            }
            $coreTotal = bcadd(bcadd($totals['shipping'], $totals['parcel_outbound'], 4), $totals['storage'], 4);
            if (bccomp($coreTotal, (string) $invoice['core_total'], 4) !== 0) {
                throw new InvalidArgumentException('MorePlus core detail total does not match Invoice core summary.');
            }

            return [
                'invoice' => $invoice,
                'totals' => $totals + [
                    'core_total' => $coreTotal,
                    'other_invoice' => (string) $invoice['other_total'],
                    'subtotal' => (string) $invoice['subtotal'],
                    'total' => (string) $invoice['total'],
                ],
                'periods' => $this->periods($rows),
                'counts' => [
                    'shipping' => count($shipping), 'parcel_outbound' => count($parcel), 'storage' => count($storage), 'total' => count($rows),
                ],
                'rows' => $rows,
            ];
        } finally {
            $zip->close();
        }
    }

    /** @param array<int,array<int,string>> $rows @return array<string,mixed> */
    private function invoice(array $rows): array
    {
        $number = $this->labeledValue($rows, 'INVOICE #');
        $client = $this->valueBelowLabel($rows, 'BILL TO');
        $dateRaw = $this->labeledValue($rows, 'INVOICE DATE');
        $invoiceDate = $dateRaw === '' ? null : $this->date(substr($dateRaw, 0, 10), 'Invoice date');

        [$headerRow, $typeColumn, $totalColumn] = $this->invoiceSummaryColumns($rows);
        $summary = [];
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= $headerRow) {
                continue;
            }
            if ($this->rowHasLabel($row, 'SUBTOTAL')) {
                break;
            }
            $label = trim((string) ($row[$typeColumn] ?? ''));
            if ($label === '') {
                continue;
            }
            $summary[$label] = $this->money((string) ($row[$totalColumn] ?? ''), 'Invoice '.$label);
        }
        foreach (['Shipping', 'Parcel Outbound', 'Storage'] as $required) {
            if (! isset($summary[$required])) {
                throw new InvalidArgumentException('MorePlus Invoice summary missing '.$required.'.');
            }
        }

        $summaryTotal = '0.0000';
        foreach ($summary as $amount) {
            $summaryTotal = bcadd($summaryTotal, $amount, 4);
        }
        $subtotalRaw = $this->labeledValue($rows, 'SUBTOTAL');
        $subtotal = $subtotalRaw === '' ? $summaryTotal : $this->money($subtotalRaw, 'Invoice subtotal');
        if (bccomp($summaryTotal, $subtotal, 4) !== 0) {
            throw new InvalidArgumentException('MorePlus Invoice summary subtotal is inconsistent.');
        }

        $discountRaw = $this->labeledValue($rows, 'DISCOUNT');
        $taxRaw = $this->labeledValue($rows, 'TAX');
        $discount = $discountRaw === '' ? '0.0000' : $this->money($discountRaw, 'Invoice discount');
        $tax = $taxRaw === '' ? '0.0000' : $this->money($taxRaw, 'Invoice tax');
        $total = $this->money($this->labeledValue($rows, 'TOTAL'), 'Invoice total');
        $calculatedTotal = bcadd(bcsub($subtotal, $discount, 4), $tax, 4);
        if (bccomp($calculatedTotal, $total, 4) !== 0) {
            throw new InvalidArgumentException('MorePlus Invoice total is inconsistent with subtotal, discount and tax.');
        }

        $core = [
            'shipping' => $summary['Shipping'],
            'parcel_outbound' => $summary['Parcel Outbound'],
            'storage' => $summary['Storage'],
        ];
        $coreTotal = bcadd(bcadd($core['shipping'], $core['parcel_outbound'], 4), $core['storage'], 4);
        $other = [];
        $otherTotal = '0.0000';
        foreach ($summary as $label => $amount) {
            if (in_array($label, ['Shipping', 'Parcel Outbound', 'Storage'], true)) {
                continue;
            }
            $otherTotal = bcadd($otherTotal, $amount, 4);
            if (bccomp($amount, '0', 4) !== 0) {
                $other[$label] = $amount;
            }
        }

        return [
            'invoice_number' => $number !== '' ? $number : null,
            'client_name' => $client !== '' ? $client : null,
            'invoice_date' => $invoiceDate,
            'currency' => 'USD',
            'totals' => $core,
            'summary_categories' => $summary,
            'other_categories' => $other,
            'core_total' => $coreTotal,
            'other_total' => $otherTotal,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
        ];
    }

    /** @param array<int,array<int,string>> $rows */
    private function labeledValue(array $rows, string $label): string
    {
        foreach ($rows as $row) {
            ksort($row);
            foreach ($row as $column => $value) {
                if (trim((string) $value) !== $label) {
                    continue;
                }
                foreach ($row as $candidateColumn => $candidateValue) {
                    $candidateValue = trim((string) $candidateValue);
                    if ((int) $candidateColumn > (int) $column && $candidateValue !== '') {
                        return $candidateValue;
                    }
                }

                return '';
            }
        }

        return '';
    }

    /** @param array<int,array<int,string>> $rows */
    private function valueBelowLabel(array $rows, string $label): string
    {
        foreach ($rows as $rowNumber => $row) {
            foreach ($row as $column => $value) {
                if (trim((string) $value) !== $label) {
                    continue;
                }
                foreach ($rows as $candidateRowNumber => $candidateRow) {
                    if ($candidateRowNumber <= $rowNumber) {
                        continue;
                    }
                    $candidate = trim((string) ($candidateRow[$column] ?? ''));
                    if ($candidate !== '') {
                        return $candidate;
                    }
                    if ($candidateRowNumber > $rowNumber + 4) {
                        break;
                    }
                }

                return '';
            }
        }

        return '';
    }

    /** @param array<int,array<int,string>> $rows @return array{int,int,int} */
    private function invoiceSummaryColumns(array $rows): array
    {
        foreach ($rows as $rowNumber => $row) {
            $typeColumn = null;
            $totalColumn = null;
            foreach ($row as $column => $value) {
                $value = trim((string) $value);
                if ($value === 'TRANSACTION TYPE') {
                    $typeColumn = (int) $column;
                }
                if ($value === 'TOTAL AMOUNT') {
                    $totalColumn = (int) $column;
                }
            }
            if ($typeColumn !== null && $totalColumn !== null) {
                return [(int) $rowNumber, $typeColumn, $totalColumn];
            }
        }
        throw new InvalidArgumentException('MorePlus Invoice summary header is missing.');
    }

    /** @param array<int,string> $row */
    private function rowHasLabel(array $row, string $label): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) === $label) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int,array<int,string>> $rows @return list<array<string,mixed>> */
    private function grouped(array $rows, string $category, string $sheet): array
    {
        $headerRequired = ['Outbound Order #', 'Transaction #', 'Transaction Amount', 'Amount Due', 'SKU', 'Qty', 'Charge Item Name', 'Charge Amount', 'Tracking #', 'Transaction Date'];
        [$headerRow, $headers] = $this->headersFromRows($rows, $headerRequired);
        $sourceOrderColumn = $this->sourceOrderReferenceColumn($rows, $headerRow, $headers);
        $out = [];
        $current = null;
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber < $headerRow + 2) {
                continue;
            }
            $raw = $this->raw($headers, $row);
            if ($this->blank($raw)) {
                continue;
            }
            $transaction = trim((string) ($raw['Transaction #'] ?? ''));
            if ($transaction !== '' && ($current === null || $transaction !== $current['transaction_number'])) {
                if ($current !== null) {
                    $out[] = $this->finishGroup($current, $category, $sheet);
                }
                $current = [
                    'source_row_number' => $rowNumber,
                    'transaction_number' => $transaction,
                    'transaction_amount' => $this->moneyNullable((string) ($raw['Transaction Amount'] ?? ''), $sheet.' transaction amount row '.$rowNumber),
                    'amount_due' => $this->moneyNullable((string) ($raw['Amount Due'] ?? ''), $sheet.' amount due row '.$rowNumber),
                    'outbound_order_number' => $this->nullable($raw['Outbound Order #'] ?? null),
                    'source_order_reference' => $sourceOrderColumn === null ? null : $this->nullable($row[$sourceOrderColumn] ?? null),
                    'tracking_number' => null,
                    'charged_on' => null,
                    'quantity' => 0, 'charge_amount' => '0.0000', 'charge_items' => [], 'raw_lines' => [],
                ];
                [$current['charged_on'], $columnOffset] = $this->dateWithColumnOffset(
                    (string) ($raw['Transaction Date'] ?? ''), $row, (int) $headers['Transaction Date'], $sheet.' date row '.$rowNumber
                );
                $trackingColumn = (int) $headers['Tracking #'] + $columnOffset;
                $current['tracking_number'] = $this->tracking($row[$trackingColumn] ?? ($raw['Tracking #'] ?? null));
            }
            if ($current === null) {
                throw new InvalidArgumentException($sheet.' continuation row appears before a transaction at row '.$rowNumber.'.');
            }
            $lineSourceReference = $sourceOrderColumn === null ? null : $this->nullable($row[$sourceOrderColumn] ?? null);
            if ($lineSourceReference !== null) {
                if ($current['source_order_reference'] === null) $current['source_order_reference'] = $lineSourceReference;
                elseif (strcasecmp((string) $current['source_order_reference'], $lineSourceReference) !== 0) throw new InvalidArgumentException($sheet.' transaction '.$current['transaction_number'].' has ambiguous source order references.');
            }
            $current['raw_lines'][] = ['row_number' => $rowNumber, 'values' => $raw, 'source_cells' => $row];
            $qty = trim((string) ($raw['Qty'] ?? ''));
            if ($qty !== '') {
                if (preg_match('/^\d+$/D', $qty) !== 1) {
                    throw new InvalidArgumentException($sheet.' quantity invalid at row '.$rowNumber.'.');
                }
                $current['quantity'] += (int) $qty;
            }
            $charge = $this->moneyNullable((string) ($raw['Charge Amount'] ?? ''), $sheet.' charge amount row '.$rowNumber);
            if ($charge !== null) {
                $current['charge_amount'] = bcadd($current['charge_amount'], $charge, 4);
            }
            $chargeName = trim((string) ($raw['Charge Item Name'] ?? ''));
            if ($chargeName !== '') {
                $current['charge_items'][$chargeName] = true;
            }
        }
        if ($current !== null) {
            $out[] = $this->finishGroup($current, $category, $sheet);
        }
        if ($out === []) {
            throw new InvalidArgumentException('MorePlus '.$sheet.' sheet has no transactions.');
        }

        return $out;
    }

    /** @param array<string,mixed> $group @return array<string,mixed> */
    private function finishGroup(array $group, string $category, string $sheet): array
    {
        $expected = array_values(array_filter([
            $group['transaction_amount'] ?? null,
            $group['amount_due'] ?? null,
        ], fn ($value): bool => $value !== null));
        if ($expected !== []) {
            $matches = false;
            foreach ($expected as $amount) {
                if (bccomp((string) $amount, (string) $group['charge_amount'], 4) === 0) {
                    $matches = true;
                    break;
                }
            }
            if (! $matches) {
                throw new InvalidArgumentException($sheet.' transaction '.$group['transaction_number'].' total does not match transaction amount or amount due.');
            }
        }
        $billableAmount = $group['amount_due'] ?? $group['transaction_amount'] ?? $group['charge_amount'];

        return [
            'category' => $category, 'source_sheet' => $sheet, 'source_row_number' => $group['source_row_number'],
            'transaction_number' => $group['transaction_number'], 'outbound_order_number' => $group['outbound_order_number'], 'source_order_reference' => $group['source_order_reference'] ?? null, 'tracking_number' => $group['tracking_number'],
            'charged_on' => $group['charged_on'], 'quantity' => $group['quantity'], 'charge_item_name' => implode(' + ', array_keys($group['charge_items'])) ?: null,
            'charge_amount' => (string) $billableAmount, 'raw_data' => $group['raw_lines'],
        ];
    }

    /** @param array<int,array<int,string>> $rows @return list<array<string,mixed>> */
    private function storage(array $rows): array
    {
        $headerRequired = ['Transaction #', 'Transaction Amount', 'Storage Date', 'Charge Item Name', 'Charge Amount'];
        [$headerRow, $headers] = $this->headersFromRows($rows, $headerRequired);
        $out = [];
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber < $headerRow + 2) {
                continue;
            }
            $raw = $this->raw($headers, $row);
            if ($this->blank($raw)) {
                continue;
            }
            $transaction = trim((string) ($raw['Transaction #'] ?? ''));
            if ($transaction === '') {
                continue;
            }
            $transactionAmount = $this->money((string) ($raw['Transaction Amount'] ?? ''), 'Storage transaction amount row '.$rowNumber);
            $charge = $this->money((string) ($raw['Charge Amount'] ?? ''), 'Storage charge amount row '.$rowNumber);
            if (bccomp($transactionAmount, $charge, 4) !== 0) {
                throw new InvalidArgumentException('Storage transaction total does not match charge at row '.$rowNumber.'.');
            }
            $out[] = [
                'category' => 'storage', 'source_sheet' => 'Storage', 'source_row_number' => $rowNumber, 'transaction_number' => $transaction,
                'outbound_order_number' => null, 'tracking_number' => null, 'charged_on' => $this->date((string) ($raw['Storage Date'] ?? ''), 'Storage date row '.$rowNumber),
                'quantity' => 0, 'charge_item_name' => $this->nullable($raw['Charge Item Name'] ?? null), 'charge_amount' => $charge,
                'raw_data' => [['row_number' => $rowNumber, 'values' => $raw, 'source_cells' => $row]],
            ];
        }
        if ($out === []) {
            throw new InvalidArgumentException('MorePlus Storage sheet has no transactions.');
        }

        return $out;
    }

    /** @param list<array<string,mixed>> $rows */
    private function sum(array $rows): string
    {
        $total = '0.0000'; foreach ($rows as $row) $total = bcadd($total, (string) $row['charge_amount'], 4); return $total;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array{start:string,end:string}> */
    private function periods(array $rows): array
    {
        $dates = [];
        foreach ($rows as $row) $dates[$row['category']][] = (string) $row['charged_on'];
        $out = [];
        foreach ($dates as $category => $values) { sort($values); $out[$category] = ['start'=>$values[0],'end'=>$values[count($values)-1]]; }
        return $out;
    }

    /** @param array<int,array<int,string>> $rows @param array<string,int> $headers */
    private function sourceOrderReferenceColumn(array $rows, int $headerRow, array $headers): ?int
    {
        foreach (['Source Order #', 'Customer Order #', 'Order Reference', 'Order #', '订单号', '组别'] as $label) {
            if (array_key_exists($label, $headers)) return (int) $headers[$label];
        }
        for ($rowNumber = $headerRow; $rowNumber <= $headerRow + 2; $rowNumber++) {
            foreach (($rows[$rowNumber] ?? []) as $column => $value) {
                if (in_array(trim((string) $value), ['Source Order #', 'Customer Order #', 'Order Reference', 'Order #', '订单号', '组别'], true)) return (int) $column;
            }
        }
        return null;
    }

    /** @param array<int,array<int,string>> $rows @param list<string> $required @return array{int,array<string,int>} */
    private function headersFromRows(array $rows, array $required): array
    {
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber > 30) {
                break;
            }
            $map = [];
            foreach ($row as $column => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $map[$value] = (int) $column;
                }
            }
            $complete = true;
            foreach ($required as $name) {
                if (! array_key_exists($name, $map)) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                return [(int) $rowNumber, $map];
            }
        }
        throw new InvalidArgumentException('MorePlus sheet header row is missing required columns.');
    }

    /** @param array<int,string> $row @param list<string> $required @return array<string,int> */
    private function headers(array $row, array $required): array
    {
        $map = [];
        foreach ($row as $column => $value) { $value = trim((string) $value); if ($value !== '') $map[$value] = (int) $column; }
        foreach ($required as $name) if (! array_key_exists($name, $map)) throw new InvalidArgumentException('MorePlus sheet missing column: '.$name);
        return $map;
    }

    /** @param array<string,int> $headers @param array<int,string> $row @return array<string,string> */
    private function raw(array $headers, array $row): array
    {
        $raw = []; foreach ($headers as $name => $column) $raw[$name] = trim((string) ($row[$column] ?? '')); return $raw;
    }

    /** @param array<string,string> $raw */
    private function blank(array $raw): bool { foreach ($raw as $value) if ($value !== '') return false; return true; }
    private function nullable(mixed $value): ?string { $value = trim((string) ($value ?? '')); return $value === '' ? null : $value; }
    private function tracking(mixed $value): ?string { $value = strtoupper(trim((string) ($value ?? ''))); return $value === '' ? null : $value; }

    /** @param array<int,string> $row @return array{string,int} */
    private function dateWithColumnOffset(string $value, array $row, int $expectedColumn, string $field): array
    {
        try {
            return [$this->date($value, $field), 0];
        } catch (InvalidArgumentException) {
        }
        $matches = [];
        foreach ($row as $column => $candidate) {
            try {
                $matches[(int) $column] = $this->date((string) $candidate, $field);
            } catch (InvalidArgumentException) {
            }
        }
        if (count($matches) !== 1) {
            throw new InvalidArgumentException($field.' is invalid or ambiguous.');
        }
        $column = (int) array_key_first($matches);

        return [$matches[$column], $column - $expectedColumn];
    }

    private function date(string $value, string $field): string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $value, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException($field.' is invalid.');
        }

        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    private function moneyNullable(string $value, string $field): ?string
    {
        $value = trim(str_replace([',','$'], '', $value)); if ($value === '') return null; return $this->money($value, $field);
    }

    private function money(string $value, string $field): string
    {
        $value = trim(str_replace([',','$'], '', $value));
        if (preg_match('/^-?\d{1,12}(?:\.\d{1,4})?$/D', $value) !== 1) throw new InvalidArgumentException($field.' is invalid.');
        return bcadd($value, '0', 4);
    }

    /** @param array<int,array<int,string>> $rows */
    private function cell(array $rows, int $row, int $column): string { return trim((string) ($rows[$row][$column-1] ?? '')); }

    private function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) throw new InvalidArgumentException('MorePlus XLSX ZIP entry count exceeds the limit.');
        $total = 0;
        for ($i=0;$i<$zip->numFiles;$i++) {
            $stat=$zip->statIndex($i); $name=str_replace('\\','/',(string)($stat['name']??''));
            if ($name===''||str_starts_with($name,'/')||in_array('..',explode('/',$name),true)) throw new InvalidArgumentException('MorePlus XLSX contains an unsafe archive path.');
            $total += (int)($stat['size']??0); if ($total>self::MAX_UNCOMPRESSED) throw new InvalidArgumentException('MorePlus XLSX uncompressed size exceeds the limit.');
        }
    }

    /** @return array<string,string> */
    private function sheetPaths(ZipArchive $zip): array
    {
        $workbook=$this->xml($zip->getFromName('xl/workbook.xml')); $rels=$this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        if(!$workbook||!$rels) throw new InvalidArgumentException('MorePlus workbook metadata is missing.');
        $relMap=[]; $rxp=new DOMXPath($rels);$rxp->registerNamespace('p','http://schemas.openxmlformats.org/package/2006/relationships');
        foreach($rxp->query('//p:Relationship') as $rel){ if($rel->getAttribute('TargetMode')==='External')continue; $target=ltrim(str_replace('\\','/',$rel->getAttribute('Target')),'/'); if($target===''||str_contains($target,'..'))continue; $relMap[$rel->getAttribute('Id')]=str_starts_with($target,'xl/')?$target:'xl/'.$target; }
        $out=[];$xp=new DOMXPath($workbook);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach($xp->query('//m:sheets/m:sheet') as $sheet){$id=$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');$name=$sheet->getAttribute('name');if($name!==''&&isset($relMap[$id]))$out[$name]=$relMap[$id];}
        return $out;
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml=$zip->getFromName('xl/sharedStrings.xml'); if(!is_string($xml)||$xml==='')return [];
        $reader=$this->reader($xml);$out=[];
        try{while($reader->read()){if($reader->nodeType!==XMLReader::ELEMENT||$reader->localName!=='si')continue;$doc=$this->xml($reader->readOuterXML());if(!$doc){$out[]='';continue;}$xp=new DOMXPath($doc);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$text='';foreach($xp->query('//m:t') as $node)$text.=$node->textContent;$out[]=$text;}}finally{$reader->close();}
        return $out;
    }

    /** @param list<string> $shared @return array<int,array<int,string>> */
    private function sheetRows(ZipArchive $zip,string $path,array $shared):array
    {
        $xml=$zip->getFromName($path);if(!is_string($xml)||$xml==='')throw new InvalidArgumentException('MorePlus worksheet is missing: '.$path);
        $reader=$this->reader($xml);$rows=[];$fallback=0;
        try{while($reader->read()){if($reader->nodeType!==XMLReader::ELEMENT||$reader->localName!=='row')continue;$fallback++;if($fallback>self::MAX_ROWS)throw new InvalidArgumentException('MorePlus worksheet row count exceeds the limit.');$number=(int)($reader->getAttribute('r')?:$fallback);$doc=$this->xml($reader->readOuterXML());if(!$doc)continue;$xp=new DOMXPath($doc);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$cells=[];foreach($xp->query('//m:c') as $cell){$column=$this->columnIndex((string)$cell->getAttribute('r'));if($column===null)continue;$type=(string)$cell->getAttribute('t');if($type==='inlineStr'){$text='';foreach($xp->query('./m:is//m:t',$cell) as $node)$text.=$node->textContent;$cells[$column]=$text;}else{$value=$xp->query('./m:v',$cell)->item(0)?->textContent??'';$cells[$column]=$type==='s'?($shared[(int)$value]??''):$value;}}$rows[$number]=$cells;}}finally{$reader->close();}
        return $rows;
    }

    private function columnIndex(string $reference):?int
    {
        if(!preg_match('/^([A-Z]+)\d+$/i',$reference,$m))return null;$index=0;foreach(str_split(strtoupper($m[1])) as $letter)$index=$index*26+ord($letter)-64;return $index-1;
    }
    private function reader(string $xml):XMLReader{ $r=new XMLReader();if(!$r->XML($xml,null,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new InvalidArgumentException('MorePlus XLSX XML is invalid.');return $r; }
    private function xml(string|false $value):?DOMDocument{if(!is_string($value)||$value==='')return null;$doc=new DOMDocument();$old=libxml_use_internal_errors(true);try{return $doc->loadXML($value,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)?$doc:null;}finally{libxml_clear_errors();libxml_use_internal_errors($old);}}
}
