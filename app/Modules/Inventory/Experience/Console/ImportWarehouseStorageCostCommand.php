<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseStorageCostImportService;
use Illuminate\Console\Command;
use Throwable;

final class ImportWarehouseStorageCostCommand extends Command
{
    protected $signature='inventory:import-warehouse-storage-cost {file : Canonical warehouse storage CSV} {--provider= : Stable provider key} {--apply : Persist immutable source evidence}
        {--expect-source-sha=} {--expect-plan-sha=} {--expect-ready=} {--expect-excluded=} {--expect-review=} {--expect-total-units=}';
    protected $description='Preview or gated-apply P7 canonical warehouse Storage pool allocation evidence.';

    public function handle(WarehouseStorageCostImportService $service):int
    {
        try{$preview=$service->preview((string)$this->argument('file'),(string)($this->option('provider')??''));}
        catch(Throwable $e){$this->line(json_encode(['mode'=>'dry_run','gate'=>'invalid_source','error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); return self::FAILURE;}
        if(!(bool)$this->option('apply')){$this->line(json_encode($this->summary($preview),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); return self::SUCCESS;}
        if(!$this->applyAllowed()){$this->error('Production --apply requires ERP_WAREHOUSE_STORAGE_COST_IMPORT_PRODUCTION_APPLY_ENABLED=true for this process.'); return self::FAILURE;}
        if(app()->environment('production')&&!$this->expectedMatches($preview)){$this->line(json_encode(['mode'=>'apply','gate'=>'blocked_preflight_mismatch','preflight'=>$this->summary($preview)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); return self::FAILURE;}
        try{$result=$service->apply((string)$this->argument('file'),(string)$this->option('provider'));}
        catch(Throwable $e){$this->line(json_encode(['mode'=>'apply','gate'=>'apply_failed','error'=>$e->getMessage(),'preflight'=>$this->summary($preview)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); return self::FAILURE;}
        $this->line(json_encode($this->summary($result)+['batch_id'=>$result['batch_id']??null,'already_applied'=>$result['already_applied']??false],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); return self::SUCCESS;
    }
    private function applyAllowed():bool { if(app()->environment(['local','testing']))return true; if(!app()->environment('production'))return false; return filter_var(getenv('ERP_WAREHOUSE_STORAGE_COST_IMPORT_PRODUCTION_APPLY_ENABLED')?:'false',FILTER_VALIDATE_BOOL); }
    private function expectedInt(string $key):?int { $v=$this->option($key); return $v!==null&&preg_match('/^\d+$/D',(string)$v)===1?(int)$v:null; }
    /** @param array<string,mixed> $p */ private function expectedMatches(array $p):bool
    { $s=strtolower(trim((string)$this->option('expect-source-sha')));$pl=strtolower(trim((string)$this->option('expect-plan-sha'))); if(preg_match('/^[a-f0-9]{64}$/D',$s)!==1||preg_match('/^[a-f0-9]{64}$/D',$pl)!==1)return false;
      $r=$this->expectedInt('expect-ready');$e=$this->expectedInt('expect-excluded');$v=$this->expectedInt('expect-review');$u=$this->expectedInt('expect-total-units');
      return !in_array(null,[$r,$e,$v,$u],true)&&hash_equals($s,(string)$p['source_checksum'])&&hash_equals($pl,(string)$p['plan_checksum'])&&$r===(int)$p['ready_count']&&$e===(int)$p['excluded_count']&&$v===(int)$p['review_count']&&$u===(int)$p['total_outbound_units']; }
    /** @param array<string,mixed> $p @return array<string,mixed> */ private function summary(array $p):array
    { return array_intersect_key($p,array_flip(['mode','provider','original_filename','source_checksum','parser_version','plan_checksum','warehouse_id','warehouse_slug','billing_period_start','billing_period_end','storage_pool_amount','currency','allocation_rate_per_unit','total_rows','ready_count','excluded_count','review_count','total_outbound_units','ready_customer_units','excluded_units','review_units','ready_customer_source_amount','excluded_source_amount','review_source_amount'])); }
}
