<?php

namespace App\Modules\Inventory\Application\WarehouseCost;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use XMLReader;
use ZipArchive;

final class SpeedaUsNyBillFileReader
{
    public const PARSER_VERSION = 'speeda-us-ny-bill-v1';
    private const MAX_FILE_BYTES = 16 * 1024 * 1024;
    private const MAX_ROWS = 50000;
    private const MAX_ZIP_ENTRIES = 240;
    private const MAX_UNCOMPRESSED = 64 * 1024 * 1024;
    private const SUMMARY = '账户汇总账单(Summary)';
    private const OUTBOUND = '出库费用明细(outbound fee)';
    private const STORAGE = '仓租费用(Bill Modify)';

    /** @return array<string,mixed> */
    public function read(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) throw new InvalidArgumentException('Speeda US-NY bill is not readable.');
        $size = filesize($real);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) throw new InvalidArgumentException('Speeda US-NY bill size is invalid or exceeds the limit.');
        if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'xlsx') throw new InvalidArgumentException('Speeda US-NY bill must be XLSX.');

        $zip = new ZipArchive;
        if ($zip->open($real) !== true) throw new InvalidArgumentException('Speeda US-NY XLSX archive is invalid.');
        try {
            $this->assertSafeArchive($zip);
            $paths = $this->sheetPaths($zip);
            foreach ([self::SUMMARY, self::OUTBOUND] as $required) {
                if (! isset($paths[$required])) throw new InvalidArgumentException('Speeda US-NY bill missing sheet: '.$required);
            }
            $shared = $this->sharedStrings($zip);
            $summaryRows = $this->sheetRows($zip, $paths[self::SUMMARY], $shared);
            $outboundRows = $this->sheetRows($zip, $paths[self::OUTBOUND], $shared);
            $storageRows = isset($paths[self::STORAGE]) ? $this->sheetRows($zip, $paths[self::STORAGE], $shared) : [];

            $invoice = $this->invoice($summaryRows);
            [$shipping, $parcel] = $this->outbound($outboundRows);
            $storage = $storageRows === [] ? [] : $this->storage($storageRows);
            $rows = [...$shipping, ...$parcel, ...$storage];
            $totals = [
                'shipping' => $this->sum($shipping),
                'parcel_outbound' => $this->sum($parcel),
                'storage' => $this->sum($storage),
            ];
            $outboundTotal = bcadd($totals['shipping'], $totals['parcel_outbound'], 4);
            if (bccomp($outboundTotal, (string) $invoice['outbound_total'], 4) !== 0) throw new InvalidArgumentException('Speeda US-NY outbound detail total does not match Summary.');
            if (bccomp($totals['storage'], (string) $invoice['totals']['storage'], 4) !== 0) throw new InvalidArgumentException('Speeda US-NY storage detail total does not match Summary.');
            $coreTotal = bcadd($outboundTotal, $totals['storage'], 4);

            return [
                'invoice' => $invoice + ['core_total' => $coreTotal],
                'totals' => $totals + [
                    'core_total' => $coreTotal,
                    'other_invoice' => (string) $invoice['other_total'],
                    'subtotal' => (string) $invoice['subtotal'],
                    'total' => (string) $invoice['total'],
                ],
                'periods' => $this->periods($rows),
                'counts' => ['shipping'=>count($shipping),'parcel_outbound'=>count($parcel),'storage'=>count($storage),'total'=>count($rows)],
                'rows' => $rows,
            ];
        } finally { $zip->close(); }
    }

    /** @param array<int,array<int,string>> $rows @return array<string,mixed> */
    private function invoice(array $rows): array
    {
        $number = $this->rightOfLabel($rows, '账单编号(Bill No)：');
        $warehouse = $this->rightOfLabel($rows, '仓库(Warehouse):');
        if ($warehouse === '' || ! str_contains($warehouse, 'US01')) throw new InvalidArgumentException('Speeda bill Summary is not canonical US-NY / US01.');
        $client = $this->rightOfLabel($rows, '客户ID(Client ID):');
        $period = $this->rightOfLabel($rows, '费用账期(Billing Period):');
        $statement = $this->rightOfLabel($rows, '账单创建时间(Statement Date):');
        $invoiceDate = $statement === '' ? null : $this->date(substr($statement, 0, 10), 'Statement date');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})至(\d{4}-\d{2}-\d{2})$/u', $period, $m) !== 1) throw new InvalidArgumentException('Speeda US-NY billing period is invalid.');

        $inbound = $this->summaryMoney($rows, '入库费用(Inbound Charges)');
        $outbound = $this->summaryMoney($rows, '出库费用(Outbound Charges)');
        $storage = $this->summaryMoney($rows, '仓租费用(Storage Charges)', true);
        $workOrder = $this->summaryMoney($rows, '工单费用(Work Order Charges)', true);
        $other = $this->summaryMoney($rows, '其他费用(Other Charges)', true);
        $subtotal = $this->summaryMoney($rows, '费用小计(SubTotal)');
        $otherTotal = bcadd(bcadd($inbound, $workOrder, 4), $other, 4);
        $calculated = bcadd(bcadd($outbound, $storage, 4), $otherTotal, 4);
        if (bccomp($calculated, $subtotal, 4) !== 0) throw new InvalidArgumentException('Speeda US-NY Summary subtotal is inconsistent.');

        $otherCategories = [];
        foreach (['Inbound'=>$inbound,'Work Order'=>$workOrder,'Other'=>$other] as $label=>$amount) if (bccomp($amount, '0', 4) !== 0) $otherCategories[$label] = $amount;
        return [
            'invoice_number'=>$number !== '' ? $number : null,
            'client_name'=>$client !== '' ? $client : null,
            'invoice_date'=>$invoiceDate,
            'currency'=>'USD',
            'billing_period'=>['start'=>$m[1],'end'=>$m[2]],
            'outbound_total'=>$outbound,
            'totals'=>['shipping'=>'0.0000','parcel_outbound'=>'0.0000','storage'=>$storage],
            'summary_categories'=>['Inbound'=>$inbound,'Outbound'=>$outbound,'Storage'=>$storage,'Work Order'=>$workOrder,'Other'=>$other],
            'other_categories'=>$otherCategories,
            'other_total'=>$otherTotal,
            'subtotal'=>$subtotal,
            'total'=>$subtotal,
        ];
    }

    /** @param array<int,array<int,string>> $rows @return array{list<array<string,mixed>>,list<array<string,mixed>>} */
    private function outbound(array $rows): array
    {
        [$headerRow, $h] = $this->header($rows, ['费用单据号','操作费','物流费','币种','计费项费用','OMS单据号','出库时间','物流跟踪号','平台单号','参考单号','SKU','数量']);
        $groups = [];
        foreach ($rows as $rowNumber=>$row) {
            if ($rowNumber <= $headerRow) continue;
            $feeNo = $this->cellBy($row, $h['费用单据号']);
            if (! str_starts_with($feeNo, 'TR#')) continue;
            $g =& $groups[$feeNo];
            if (! isset($g)) $g = ['source_row_number'=>$rowNumber,'fee_no'=>$feeNo,'oms'=>null,'date'=>null,'tracking'=>null,'reference'=>null,'shipping'=>'0.0000','handling'=>'0.0000','qty'=>0,'skus'=>[],'raw'=>[]];
            $g['raw'][] = ['row_number'=>$rowNumber,'source_cells'=>$row];
            $this->assignEvidence($g, 'oms', $this->cellBy($row,$h['OMS单据号']), '/^OBS/i');
            $this->assignEvidence($g, 'tracking', $this->cellBy($row,$h['物流跟踪号']), '/^[A-Z0-9]{10,}$/i');
            $this->assignEvidence($g, 'reference', $this->cellBy($row,$h['参考单号']), '/^(?:C\d|KOL)/i');
            $date = $this->cellBy($row,$h['出库时间']);
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) $g['date'] ??= $this->date(substr($date,0,10), 'Outbound date row '.$rowNumber);
            $platformOrder = $this->cellBy($row, $h['平台单号']);
            $operation = $this->moneyUnlessPlatformPlaceholder($this->cellBy($row, $h['操作费']), $platformOrder);
            $shipping = $this->moneyUnlessPlatformPlaceholder($this->cellBy($row, $h['物流费']), $platformOrder);
            if ($operation !== null) $g['handling'] = bcadd($g['handling'], $operation, 4);
            if ($shipping !== null) $g['shipping'] = bcadd($g['shipping'], $shipping, 4);
            $sku = $this->cellBy($row,$h['SKU']);
            $qty = $this->positiveInt($this->cellBy($row,$h['数量']));
            if ($sku !== '' && ! ctype_digit($sku)) { $g['skus'][] = ['sku'=>$sku,'qty'=>$qty]; $g['qty'] += $qty; }
            unset($g);
        }
        if ($groups === []) throw new InvalidArgumentException('Speeda US-NY outbound sheet has no transactions.');

        $shippingRows=[]; $parcelRows=[];
        foreach ($groups as $g) {
            if ($g['date'] === null) $g['date'] = $this->transactionDate((string) $g['fee_no'], 'Outbound transaction '.$g['fee_no']);
            if ($g['tracking'] === null) throw new InvalidArgumentException('Speeda US-NY outbound transaction '.$g['fee_no'].' lacks strict tracking evidence.');
            if (bccomp($g['shipping'],'0',4) !== 0) $shippingRows[]=$this->normalized($g,'shipping',$g['shipping'],'Last-mile shipping');
            if (bccomp($g['handling'],'0',4) !== 0) $parcelRows[]=$this->normalized($g,'parcel_outbound',$g['handling'],'Outbound operation / packaging');
        }
        return [$shippingRows,$parcelRows];
    }

    /** @param array<string,mixed> $g @return array<string,mixed> */
    private function normalized(array $g,string $category,string $amount,string $label): array
    {
        return ['category'=>$category,'source_sheet'=>self::OUTBOUND,'source_row_number'=>$g['source_row_number'],'transaction_number'=>$g['fee_no'],'outbound_order_number'=>$g['oms'],'source_order_reference'=>$g['reference'],'tracking_number'=>$g['tracking'],'charged_on'=>$g['date'],'quantity'=>$g['qty'],'charge_item_name'=>$label,'charge_amount'=>$amount,'raw_data'=>['lines'=>$g['raw'],'skus'=>$g['skus']]];
    }

    /** @param array<int,array<int,string>> $rows @return list<array<string,mixed>> */
    private function storage(array $rows): array
    {
        [$headerRow,$h]=$this->header($rows,['费用单据号','计费时间','费用小计','币种']); $out=[];
        foreach($rows as $rowNumber=>$row){if($rowNumber<=$headerRow)continue;$no=$this->cellBy($row,$h['费用单据号']);if(!str_starts_with($no,'TR#'))continue;$amount=$this->moneyNullable($this->cellBy($row,$h['费用小计']));if($amount===null)continue;$chargedOn=$this->transactionDate($no,'Storage transaction row '.$rowNumber);$out[]=['category'=>'storage','source_sheet'=>self::STORAGE,'source_row_number'=>$rowNumber,'transaction_number'=>$no,'outbound_order_number'=>null,'source_order_reference'=>null,'tracking_number'=>null,'charged_on'=>$chargedOn,'quantity'=>0,'charge_item_name'=>'Storage','charge_amount'=>$amount,'raw_data'=>[['row_number'=>$rowNumber,'source_cells'=>$row,'billing_generated_at'=>$this->cellBy($row,$h['计费时间'])]]];}
        return $out;
    }

    /** @param array<int,array<int,string>> $rows @param list<string> $required @return array{int,array<string,int>} */
    private function header(array $rows,array $required): array
    {
        foreach($rows as $rowNumber=>$row){if($rowNumber>8)break;$map=[];foreach($row as $col=>$value){$v=preg_replace('/\s+/u',' ',trim((string)$value));foreach($required as $needle)if(str_starts_with($v,$needle.' ')||$v===$needle)$map[$needle]=(int)$col;}if(count($map)===count($required))return[(int)$rowNumber,$map];}
        throw new InvalidArgumentException('Speeda US-NY sheet header row is missing required columns.');
    }

    /** @param array<string,mixed> $group */
    private function assignEvidence(array &$group,string $key,string $value,string $pattern): void
    {
        if($value===''||preg_match($pattern,$value)!==1)return;if($group[$key]===null)$group[$key]=$value;elseif(strcasecmp((string)$group[$key],$value)!==0)throw new InvalidArgumentException('Speeda US-NY transaction '.$group['fee_no'].' has conflicting '.$key.' evidence.');
    }
    private function positiveInt(string $value): int { return preg_match('/^\d+$/D',$value)===1 ? (int)$value : 0; }
    private function cellBy(array $row,int $column): string { return trim((string)($row[$column]??'')); }
    private function summaryMoney(array $rows,string $label,bool $optional=false): string { $v=$this->rightOfLabel($rows,$label);if($v===''&&$optional)return'0.0000';if($v==='')throw new InvalidArgumentException('Speeda US-NY Summary missing '.$label.'.');return $this->money($v,$label); }
    private function rightOfLabel(array $rows,string $label): string { foreach($rows as $row)foreach($row as $col=>$v)if(trim((string)$v)===$label){for($c=(int)$col+1;$c<=(int)$col+3;$c++){ $x=trim((string)($row[$c]??''));if($x!=='')return$x;}return'';}return''; }
    private function moneyNullable(string $value): ?string { $value=trim(str_replace([',','$'],'',$value));if($value===''||preg_match('/^-?\d{1,12}(?:\.\d{1,4})?$/D',$value)!==1)return null;return bcadd($value,'0',4); }
    private function moneyUnlessPlatformPlaceholder(string $value,string $platformOrder): ?string { $raw=trim($value);$platform=trim($platformOrder);if($raw===''||($platform!==''&&$raw===$platform))return null;return $this->moneyNullable($raw); }
    private function money(string $value,string $field): string { $v=$this->moneyNullable($value);if($v===null)throw new InvalidArgumentException($field.' is invalid.');return$v; }
    private function date(string $value,string $field): string { if(preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/D',trim($value),$m)!==1||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new InvalidArgumentException($field.' is invalid.');return sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]); }
    private function transactionDate(string $transaction,string $field): string { if(preg_match('/^TR#(\d{4})(\d{2})(\d{2})\d+$/D',$transaction,$m)!==1||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new InvalidArgumentException($field.' has no canonical transaction date.');return sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]); }
    /** @param list<array<string,mixed>> $rows */ private function sum(array $rows): string { $t='0.0000';foreach($rows as $r)$t=bcadd($t,(string)$r['charge_amount'],4);return$t; }
    /** @param list<array<string,mixed>> $rows @return array<string,array{start:string,end:string}> */ private function periods(array $rows): array { $d=[];foreach($rows as $r)$d[$r['category']][]=(string)$r['charged_on'];$o=[];foreach($d as $c=>$v){sort($v);$o[$c]=['start'=>$v[0],'end'=>$v[count($v)-1]];}return$o; }

    private function assertSafeArchive(ZipArchive $zip): void { if($zip->numFiles>self::MAX_ZIP_ENTRIES)throw new InvalidArgumentException('Speeda US-NY XLSX ZIP entry count exceeds the limit.');$total=0;for($i=0;$i<$zip->numFiles;$i++){$s=$zip->statIndex($i);$name=str_replace('\\','/',(string)($s['name']??''));if($name===''||str_starts_with($name,'/')||in_array('..',explode('/',$name),true))throw new InvalidArgumentException('Speeda US-NY XLSX contains an unsafe archive path.');$total+=(int)($s['size']??0);if($total>self::MAX_UNCOMPRESSED)throw new InvalidArgumentException('Speeda US-NY XLSX uncompressed size exceeds the limit.');} }
    /** @return array<string,string> */ private function sheetPaths(ZipArchive $zip): array { $workbook=$this->xml($zip->getFromName('xl/workbook.xml'));$rels=$this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));if(!$workbook||!$rels)throw new InvalidArgumentException('Speeda US-NY workbook metadata is missing.');$relMap=[];$rxp=new DOMXPath($rels);$rxp->registerNamespace('p','http://schemas.openxmlformats.org/package/2006/relationships');foreach($rxp->query('//p:Relationship') as $rel){if($rel->getAttribute('TargetMode')==='External')continue;$target=ltrim(str_replace('\\','/',$rel->getAttribute('Target')),'/');if($target===''||str_contains($target,'..'))continue;$relMap[$rel->getAttribute('Id')]=str_starts_with($target,'xl/')?$target:'xl/'.$target;}$out=[];$xp=new DOMXPath($workbook);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');foreach($xp->query('//m:sheets/m:sheet') as $sheet){$id=$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');$name=$sheet->getAttribute('name');if($name!==''&&isset($relMap[$id]))$out[$name]=$relMap[$id];}return$out; }
    /** @return list<string> */ private function sharedStrings(ZipArchive $zip): array { $xml=$zip->getFromName('xl/sharedStrings.xml');if(!is_string($xml)||$xml==='')return[];$reader=$this->reader($xml);$out=[];try{while($reader->read()){if($reader->nodeType!==XMLReader::ELEMENT||$reader->localName!=='si')continue;$doc=$this->xml($reader->readOuterXML());if(!$doc){$out[]='';continue;}$xp=new DOMXPath($doc);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$text='';foreach($xp->query('//m:t') as $node)$text.=$node->textContent;$out[]=$text;}}finally{$reader->close();}return$out; }
    /** @param list<string> $shared @return array<int,array<int,string>> */ private function sheetRows(ZipArchive $zip,string $path,array $shared): array { $xml=$zip->getFromName($path);if(!is_string($xml)||$xml==='')throw new InvalidArgumentException('Speeda US-NY worksheet is missing: '.$path);$reader=$this->reader($xml);$rows=[];$fallback=0;try{while($reader->read()){if($reader->nodeType!==XMLReader::ELEMENT||$reader->localName!=='row')continue;$fallback++;if($fallback>self::MAX_ROWS)throw new InvalidArgumentException('Speeda US-NY worksheet row count exceeds the limit.');$number=(int)($reader->getAttribute('r')?:$fallback);$doc=$this->xml($reader->readOuterXML());if(!$doc)continue;$xp=new DOMXPath($doc);$xp->registerNamespace('m','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$cells=[];foreach($xp->query('//m:c') as $cell){$column=$this->columnIndex((string)$cell->getAttribute('r'));if($column===null)continue;$type=(string)$cell->getAttribute('t');if($type==='inlineStr'){$text='';foreach($xp->query('./m:is//m:t',$cell) as $node)$text.=$node->textContent;$cells[$column]=$text;}else{$value=$xp->query('./m:v',$cell)->item(0)?->textContent??'';$cells[$column]=$type==='s'?($shared[(int)$value]??''):$value;}}$rows[$number]=$cells;}}finally{$reader->close();}return$rows; }
    private function columnIndex(string $reference): ?int { if(!preg_match('/^([A-Z]+)\d+$/i',$reference,$m))return null;$index=0;foreach(str_split(strtoupper($m[1])) as $letter)$index=$index*26+ord($letter)-64;return$index-1; }
    private function reader(string $xml): XMLReader { $r=new XMLReader();if(!$r->XML($xml,null,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new InvalidArgumentException('Speeda US-NY XLSX XML is invalid.');return$r; }
    private function xml(string|false $value): ?DOMDocument { if(!is_string($value)||$value==='')return null;$doc=new DOMDocument();$old=libxml_use_internal_errors(true);try{return$doc->loadXML($value,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)?$doc:null;}finally{libxml_clear_errors();libxml_use_internal_errors($old);} }
}
