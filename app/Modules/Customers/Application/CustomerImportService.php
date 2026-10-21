<?php

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\CountryCodeNormalizer;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerAddress;
use App\Modules\Customers\Infrastructure\Models\CustomerGroup;
use App\Modules\Customers\Infrastructure\Models\CustomerImportBatch;
use App\Modules\Customers\Infrastructure\Models\CustomerImportRow;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;
use DOMDocument;
use DOMXPath;

final class CustomerImportService
{
    public const COLUMNS = ['customer_number','name','email','phone','country_code','language_code','source_type','owner_email','notes','status','manual_groups','billing_name','billing_company','billing_phone','billing_country_code','billing_province_state','billing_city','billing_address_line_1','billing_address_line_2','billing_postal_code','shipping_name','shipping_company','shipping_phone','shipping_country_code','shipping_province_state','shipping_city','shipping_address_line_1','shipping_address_line_2','shipping_postal_code'];
    private const MAX_FILE = 10 * 1024 * 1024;
    private const MAX_ROWS = 5000;
    private const MAX_ZIP_ENTRIES = 200;
    private const MAX_UNCOMPRESSED = 25 * 1024 * 1024;
    private const ADDRESS_FIELDS = ['name','company','phone','country_code','province_state','city','address_line_1','address_line_2','postal_code'];
    private const ALIASES = [
        'customer_number' => ['customer_number','customer number','客户编号','客户号'], 'name' => ['name','customer name','姓名','客户名称'], 'email' => ['email','e-mail','邮箱','电子邮箱'], 'phone' => ['phone','telephone','mobile','电话','手机'], 'country_code' => ['country_code','country','国家代码','国家'], 'language_code' => ['language_code','language','语言代码','语言'], 'source_type' => ['source_type','source','来源'], 'owner_email' => ['owner_email','owner email','负责人邮箱','客户负责人'], 'notes' => ['notes','note','备注'], 'status' => ['status','状态'], 'manual_groups' => ['manual_groups','manual groups','groups','手动分组','客户分组'],
        'billing_name' => ['billing_name','billing name','账单姓名'], 'billing_company' => ['billing_company','billing company','账单公司'], 'billing_phone' => ['billing_phone','billing phone','账单电话'], 'billing_country_code' => ['billing_country_code','billing country','账单国家'], 'billing_province_state' => ['billing_province_state','billing state','账单省州'], 'billing_city' => ['billing_city','billing city','账单城市'], 'billing_address_line_1' => ['billing_address_line_1','billing address 1','账单地址1'], 'billing_address_line_2' => ['billing_address_line_2','billing address 2','账单地址2'], 'billing_postal_code' => ['billing_postal_code','billing postal code','账单邮编'],
        'shipping_name' => ['shipping_name','shipping name','收货姓名'], 'shipping_company' => ['shipping_company','shipping company','收货公司'], 'shipping_phone' => ['shipping_phone','shipping phone','收货电话'], 'shipping_country_code' => ['shipping_country_code','shipping country','收货国家'], 'shipping_province_state' => ['shipping_province_state','shipping state','收货省州'], 'shipping_city' => ['shipping_city','shipping city','收货城市'], 'shipping_address_line_1' => ['shipping_address_line_1','shipping address 1','收货地址1'], 'shipping_address_line_2' => ['shipping_address_line_2','shipping address 2','收货地址2'], 'shipping_postal_code' => ['shipping_postal_code','shipping postal code','收货邮编'],
    ];

    public function __construct(private readonly CustomerService $customers) {}

    public function preview(UploadedFile $file, ?Authenticatable $actor): CustomerImportBatch
    {
        if ($file->getSize() > self::MAX_FILE) abort(422, '文件超过 10 MiB 限制。');
        $source = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($source, ['csv','xlsx'], true)) abort(422, '仅支持 CSV 或 XLSX 文件。');
        [$headers, $rawRows, $unknown] = $source === 'csv' ? $this->readCsv($file) : $this->readXlsx($file);
        if ($headers === []) abort(422, '文件缺少表头或格式无效。');
        if (count($rawRows) > self::MAX_ROWS) abort(422, '单批次最多 5,000 条非空数据行。');
        $map = $this->headerMap($headers);
        if ($map['duplicates'] !== []) abort(422, '存在规范化后重复的列：'.implode(', ', $map['duplicates']));
        $unknown = $map['unknown'];
        $batch = DB::transaction(function () use ($file, $source, $headers, $rawRows, $unknown, $map, $actor): CustomerImportBatch {
            $batch = CustomerImportBatch::create(['source_type'=>$source,'original_filename'=>Str::limit((string)$file->getClientOriginalName(),255,''),'checksum'=>hash_file('sha256',$file->getRealPath()),'status'=>'preview','creator_user_id'=>$actor?->getAuthIdentifier()]);
            $seen = [];
            $stats = ['total'=>0,'create'=>0,'update'=>0,'unchanged'=>0,'warning'=>0,'error'=>0];
            foreach ($rawRows as $offset => $raw) {
                $normalized = []; $present = [];
                foreach ($map['columns'] as $key => $idx) { $present[]=$key; $normalized[$key]=$this->normalizeValue($key, $raw[$idx] ?? null); }
                $warnings = $unknown !== [] ? ['未知列已忽略：'.implode(', ', $unknown)] : [];
                [$customer, $identityError] = $this->resolve($normalized);
                $duplicateError = $this->duplicateIdentityError($normalized, $customer, $seen);
                $errors = array_filter([$identityError, $duplicateError, ...$this->validate($normalized, $present, $customer)]);
                $status = $errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'ok');
                $action = $customer ? ($this->wouldChange($customer, $normalized, $present) ? 'update' : 'unchanged') : 'create';
                $stats['total']++; $stats[$action]++; if ($status !== 'ok') $stats[$status]++;
                $message = implode('；', [...$warnings, ...$errors]) ?: null;
                CustomerImportRow::create(['import_batch_id'=>$batch->id,'source_row_no'=>$offset+2,'raw_data'=>$raw,'normalized_data'=>$normalized,'present_fields'=>$present,'target_customer_id'=>$customer?->id,'action'=>$action,'validation_status'=>$status,'validation_message'=>$message]);
            }
            $batch->update(['preview_total_rows'=>$stats['total'],'preview_create_rows'=>$stats['create'],'preview_update_rows'=>$stats['update'],'preview_unchanged_rows'=>$stats['unchanged'],'preview_warning_rows'=>$stats['warning'],'preview_error_rows'=>$stats['error']]);
            return $batch->load('rows');
        });
        return $batch;
    }

    public function confirm(CustomerImportBatch $batch, ?Authenticatable $actor): CustomerImportBatch
    {
        return DB::transaction(function () use ($batch, $actor): CustomerImportBatch {
            $batch = CustomerImportBatch::query()->lockForUpdate()->with('rows')->findOrFail($batch->id);
            if ($batch->status !== 'preview') abort(422, '批次已确认或已结束，不可重复确认。');
            if ($batch->preview_error_rows > 0) abort(422, '存在错误行，无法确认。');
            foreach ($batch->rows as $row) {
                $customer = $row->target_customer_id ? Customer::query()->lockForUpdate()->findOrFail($row->target_customer_id) : null;
                $data = $row->normalized_data ?? []; $present = $row->present_fields ?? [];
                if ($customer === null) {
                    $customer = $this->customers->create($this->masterData($data, $present, true), $actor);
                } elseif ($row->action === 'update') {
                    $master = $this->masterData($data, $present, false);
                    if ($master !== [] && $this->masterWouldChange($customer, $master)) {
                        $customer = $this->customers->update($customer, $master, $actor);
                    }
                }
                $this->syncAddress($customer, $data, $present, 'billing'); $this->syncAddress($customer, $data, $present, 'shipping');
                $this->syncGroups($customer, $data, $present, $actor);
                if (in_array('status', $present, true)) $this->status($data['status'] ?? null) === 'archived' ? $this->customers->archive($customer, $actor) : $this->customers->restore($customer, $actor);
            }
            $batch->update(['status'=>'confirmed','confirmer_user_id'=>$actor?->getAuthIdentifier(),'confirmed_at'=>now()]);
            return $batch->fresh(['rows','creator','confirmer']);
        });
    }

    public function history(): mixed { return CustomerImportBatch::query()->with(['creator','confirmer'])->latest('id')->limit(20)->get(); }

    public function headers(): array { return self::COLUMNS; }

    public function exportRows(array $filters): array
    {
        return $this->customers->allForExport($filters)->map(function (Customer $c): array {
            $values = ['customer_number'=>$c->customer_number,'name'=>$c->name,'email'=>$c->email,'phone'=>$c->phone,'country_code'=>$c->country_code,'language_code'=>$c->language_code,'source_type'=>$c->source_type,'owner_email'=>$c->owner?->email,'notes'=>$c->notes,'status'=>$c->archived_at ? 'archived' : 'active','manual_groups'=>json_encode($c->groups->pluck('name')->sort()->values()->all(), JSON_UNESCAPED_UNICODE)];
            foreach (['billing','shipping'] as $type) { $a=$type==='billing' ? $c->billingAddress : $c->shippingAddress; foreach (self::ADDRESS_FIELDS as $field) $values[$type.'_'.$field]=$a?->{$field}; }
            return $values;
        })->all();
    }

    public function xlsx(array $rows): string
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $sheetRows = [array_combine(self::COLUMNS, self::COLUMNS), ...$rows];
        foreach ($sheetRows as $rowIndex => $row) {
            $sheet .= '<row r="'.($rowIndex + 1).'">';
            foreach (self::COLUMNS as $columnIndex => $key) {
                $value = $row[$key] ?? null;
                if ($value === null || $value === '') continue;
                $ref = $this->columnName($columnIndex + 1).($rowIndex + 1);
                $sheet .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $path = tempnam(sys_get_temp_dir(), 'customer-xlsx-');
        if ($path === false) throw new \RuntimeException('Unable to create XLSX temporary file.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($path); throw new \RuntimeException('Unable to create XLSX archive.'); }
        try {
            $zip->addFromString('[Content_Types].xml','<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml','<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Customers" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        } finally {
            $zip->close();
        }
        $bytes = file_get_contents($path); @unlink($path);
        if ($bytes === false) throw new \RuntimeException('Unable to read XLSX archive.');
        return $bytes;
    }

    private function readCsv(UploadedFile $file): array { $h=fopen($file->getRealPath(),'rb'); $headers=fgetcsv($h) ?: []; if (isset($headers[0])) $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]); $rows=[]; while (($r=fgetcsv($h))!==false) { $r=array_map(fn($v)=>is_string($v)?trim($v):$v,$r); if (collect($r)->filter(fn($v)=>$v!==null&&$v!=='')->isEmpty()) continue; $rows[]=$r; } fclose($h); return [$headers,$rows,[]]; }
    private function readXlsx(UploadedFile $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) abort(422, 'XLSX 文件无效。');
        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) abort(422, 'XLSX ZIP 条目超限。');
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_UNCOMPRESSED) abort(422, 'XLSX 解压大小超限。');
            }
            $sheetPath = $this->firstWorksheetPath($zip);
            if ($sheetPath === null) abort(422, 'XLSX 工作表结构无效。');
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) abort(422, 'XLSX 第一工作表不存在。');
            $rows = $this->sheetRows($sheetXml, $this->sharedStrings($zip));
            if ($rows === []) abort(422, 'XLSX 第一工作表为空或格式无效。');
            $headers = array_shift($rows) ?? [];
            return [$headers, $rows, []];
        } finally {
            $zip->close();
        }
    }

    private function firstWorksheetPath(ZipArchive $zip): ?string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) return null;
        $workbook = $this->xml($workbookXml); $rels = $this->xml($relsXml);
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
        if ($relationship === null || $relationship->getAttribute('TargetMode') === 'External') return null;
        $target = str_replace('\\', '/', (string) $relationship->getAttribute('Target'));
        if ($target === '' || str_contains($target, '..')) return null;
        $target = ltrim($target, '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $doc = $this->xml($xml); if ($doc === null) return [];
        $xp = new DOMXPath($doc); $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $result = [];
        foreach ($xp->query('//m:si') as $si) {
            $parts = []; foreach ($xp->query('.//m:t', $si) as $node) $parts[] = $node->textContent;
            $result[] = implode('', $parts);
        }
        return $result;
    }

    private function sheetRows(string $xml, array $sharedStrings): array
    {
        $doc = $this->xml($xml); if ($doc === null) abort(422, 'XLSX 工作表 XML 无效。');
        $xp = new DOMXPath($doc); $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($xp->query('//m:sheetData/m:row') as $rowNode) {
            $cells = [];
            foreach ($xp->query('./m:c', $rowNode) as $cell) {
                $ref = $cell->getAttribute('r');
                if (! preg_match('/^([A-Z]+)\d+$/i', $ref, $m)) continue;
                $idx = $this->columnIndex(strtoupper($m[1]));
                $type = $cell->getAttribute('t');
                $value = $xp->query('./m:v', $cell)->item(0)?->textContent;
                if ($type === 's') $value = $sharedStrings[(int) $value] ?? '';
                elseif ($type === 'inlineStr') { $parts=[]; foreach ($xp->query('./m:is//m:t', $cell) as $node) $parts[]=$node->textContent; $value=implode('', $parts); }
                $cells[$idx] = $value;
            }
            if ($cells === []) continue;
            $max = max(array_keys($cells)); $rows[] = array_map(fn ($i) => $cells[$i] ?? null, range(0, $max));
        }
        return $rows;
    }

    private function xml(string|false $value): ?DOMDocument
    {
        if ($value === false || $value === '') return null;
        $doc = new DOMDocument(); $old = libxml_use_internal_errors(true);
        try { return $doc->loadXML($value, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ? $doc : null; }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }

    private function columnIndex(string $letters): int
    {
        $index = 0; foreach (str_split($letters) as $letter) $index = ($index * 26) + (ord($letter) - 64);
        return $index - 1;
    }

    private function headerMap(array $headers): array { $columns=[];$dupes=[];$unknown=[]; foreach($headers as $i=>$h){$n=mb_strtolower(trim((string)$h));$found=null;foreach(self::ALIASES as $key=>$aliases)if(in_array($n,$aliases,true)||in_array(str_replace(' ','_',$n),$aliases,true)){$found=$key;break;}if($found===null){if($n!=='')$unknown[]=(string)$h;continue;}if(isset($columns[$found]))$dupes[]=$found;else$columns[$found]=$i;}return ['columns'=>$columns,'duplicates'=>$dupes,'unknown'=>$unknown]; }
    private function clean(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function normalizeValue(string $field, mixed $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) return null;
        if (in_array($field, ['email', 'owner_email'], true)) return strtolower($value);
        if (in_array($field, ['country_code', 'billing_country_code', 'shipping_country_code'], true)) return strtoupper($value);
        if ($field === 'source_type') return strtolower($value);
        if ($field === 'status') return $this->status($value);
        if ($field === 'manual_groups') return json_encode($this->groups($value), JSON_UNESCAPED_UNICODE);
        return $value;
    }

    private function resolve(array $d): array
    {
        $number = $this->clean($d['customer_number'] ?? null);
        $email = $this->clean($d['email'] ?? null);
        $phone = $this->clean($d['phone'] ?? null);
        $byNumber = null;
        if ($number !== null) {
            if (! preg_match('/^CUS-(\d+)$/i', $number, $match)) return [null, '客户编号格式无效。'];
            $byNumber = Customer::find((int) $match[1]);
            if ($byNumber === null) return [null, '客户编号不存在。'];
        }
        $byEmail = $email ? Customer::whereRaw('LOWER(email)=?', [strtolower($email)])->get() : collect();
        $byPhone = $phone ? Customer::where('phone', trim($phone))->get() : collect();
        if ($byEmail->count() > 1 || $byPhone->count() > 1) return [null, '邮箱或电话匹配不唯一。'];
        $chosen = $byNumber ?: ($byEmail->count() === 1 ? $byEmail->first() : ($byPhone->count() === 1 ? $byPhone->first() : null));
        $ids = collect([$byNumber?->id, $byEmail->first()?->id, $byPhone->first()?->id])->filter()->unique();
        if ($ids->count() > 1) return [null, '提供的身份标识冲突。'];
        return [$chosen, null];
    }

    private function duplicateIdentityError(array $d, ?Customer $customer, array &$seen): ?string
    {
        $keys = [];
        if ($customer) $keys[] = 'id:'.$customer->id;
        if (! empty($d['email'])) $keys[] = 'email:'.strtolower(trim($d['email']));
        if (! empty($d['phone'])) $keys[] = 'phone:'.trim($d['phone']);
        foreach ($keys as $key) if (isset($seen[$key])) return '文件中存在重复稳定身份。';
        foreach ($keys as $key) $seen[$key] = true;
        return null;
    }

    private function validate(array $d, array $present, ?Customer $customer): array
    {
        $errors = [];
        if (! $customer && empty($d['name'])) $errors[] = '新客户必须提供姓名。';
        if ($customer && in_array('name', $present, true) && $d['name'] === null) $errors[] = '客户姓名不可置空。';
        if (! $customer && empty($d['email']) && empty($d['phone'])) $errors[] = '新客户必须提供邮箱或电话。';
        if (in_array('email', $present, true) && $d['email'] !== null && ! filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = '邮箱格式无效。';
        foreach (['country_code', 'billing_country_code', 'shipping_country_code'] as $countryField) {
            if (! in_array($countryField, $present, true) || $d[$countryField] === null) continue;
            try { CountryCodeNormalizer::normalize($d[$countryField]); } catch (\Throwable) { $errors[] = $countryField.' 国家代码无效。'; }
        }
        if (in_array('source_type', $present, true) && ! in_array($d['source_type'], ['manual','website','woocommerce','csv_import','external_platform'], true)) $errors[] = '来源类型无效。';
        if (in_array('status', $present, true) && ! in_array($this->status($d['status']), ['active','archived'], true)) $errors[] = '状态无效。';
        if (in_array('owner_email', $present, true) && $d['owner_email'] !== null && User::whereRaw('LOWER(email)=?', [strtolower($d['owner_email'])])->where('is_active', true)->count() !== 1) $errors[] = '负责人不存在或未激活。';
        if (in_array('manual_groups', $present, true) && $d['manual_groups'] !== null) {
            $names = $this->groups($d['manual_groups']);
            $known = CustomerGroup::whereIn('name', $names)->get(['id','name']);
            if ($known->count() !== count($names) || $known->pluck('name')->unique()->count() !== count($names)) $errors[] = '包含未知或不唯一的手动分组。';
        }
        return $errors;
    }

    private function status(?string $v): string
    {
        $v = mb_strtolower(trim((string) $v));
        return match ($v) { 'active','1','true','启用','有效','活动' => 'active', 'archived','0','false','归档','已归档' => 'archived', default => $v };
    }

    private function masterData(array $d, array $present, bool $create): array
    {
        $out = [];
        foreach (['name','email','phone','country_code','language_code','notes'] as $field) if (in_array($field, $present, true)) $out[$field] = $d[$field];
        if (in_array('source_type', $present, true)) $out['source_type'] = $d['source_type']; elseif ($create) $out['source_type'] = 'csv_import';
        if (in_array('owner_email', $present, true)) $out['owner_id'] = $d['owner_email'] === null ? null : User::whereRaw('LOWER(email)=?', [strtolower($d['owner_email'])])->where('is_active', true)->value('id');
        return $out;
    }

    private function masterWouldChange(Customer $customer, array $master): bool
    {
        foreach ($master as $field => $value) {
            $old = $customer->{$field};
            if ($field === 'email') $old = $old === null ? null : strtolower((string) $old);
            if ($field === 'country_code') $old = $old === null ? null : strtoupper((string) $old);
            if ((string) ($old ?? '') !== (string) ($value ?? '')) return true;
        }
        return false;
    }

    private function wouldChange(Customer $customer, array $data, array $present): bool
    {
        if ($this->masterWouldChange($customer, $this->masterData($data, $present, false))) return true;
        if (in_array('status', $present, true) && (($customer->archived_at !== null) !== ($this->status($data['status']) === 'archived'))) return true;
        if ($this->addressWouldChange($customer, $data, $present, 'billing')) return true;
        if ($this->addressWouldChange($customer, $data, $present, 'shipping')) return true;
        return $this->groupsWouldChange($customer, $data, $present);
    }

    private function addressWouldChange(Customer $customer, array $data, array $present, string $type): bool
    {
        $provided = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $key = $type.'_'.$field;
            if (in_array($key, $present, true)) $provided[$field] = $data[$key];
        }
        if ($provided === []) return false;
        $address = $customer->addresses()->where('address_type', $type)->first();
        $result = [];
        foreach (self::ADDRESS_FIELDS as $field) $result[$field] = array_key_exists($field, $provided) ? $provided[$field] : $address?->{$field};
        $hasContent = collect($result)->contains(fn ($value) => $value !== null && $value !== '');
        if (! $hasContent) return $address !== null;
        if ($address === null) return true;
        foreach ($result as $field => $value) if ((string) ($address->{$field} ?? '') !== (string) ($value ?? '')) return true;
        return false;
    }

    private function syncAddress(Customer $customer, array $data, array $present, string $type): void
    {
        if (! $this->addressWouldChange($customer, $data, $present, $type)) return;
        $fields = [];
        foreach (self::ADDRESS_FIELDS as $field) { $key = $type.'_'.$field; if (in_array($key, $present, true)) $fields[$field] = $data[$key]; }
        $address = $customer->addresses()->where('address_type', $type)->first();
        $merged = []; foreach (self::ADDRESS_FIELDS as $field) $merged[$field] = array_key_exists($field, $fields) ? $fields[$field] : $address?->{$field};
        if (collect($merged)->filter(fn ($value) => $value !== null && $value !== '')->isEmpty()) { $address?->delete(); return; }
        CustomerAddress::updateOrCreate(['customer_id'=>$customer->id,'address_type'=>$type], $merged);
    }

    private function groups(?string $value): array
    {
        if ($value === null || trim($value) === '') return [];
        $json = json_decode($value, true); $names = is_array($json) ? $json : explode(';', $value);
        $names = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $names))));
        sort($names, SORT_STRING);
        return $names;
    }

    private function groupsWouldChange(Customer $customer, array $data, array $present): bool
    {
        if (! in_array('manual_groups', $present, true)) return false;
        $desired = $this->groups($data['manual_groups'] ?? null);
        $current = $customer->groups()->pluck('name')->all(); sort($current, SORT_STRING);
        return $desired !== $current;
    }

    private function syncGroups(Customer $customer, array $data, array $present, ?Authenticatable $actor): void
    {
        if (! $this->groupsWouldChange($customer, $data, $present)) return;
        $groups = CustomerGroup::whereIn('name', $this->groups($data['manual_groups'] ?? null))->get();
        $customer->groups()->syncWithPivotValues($groups->pluck('id')->all(), ['added_by' => $actor?->getAuthIdentifier()]);
    }

    private function columnName(int $n): string { $s='';while($n>0){$n--; $s=chr(65+$n%26).$s;$n=intdiv($n,26);}return $s; }
}
