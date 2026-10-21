<?php

namespace App\Modules\Inventory\Experience\Console;

use App\Modules\Inventory\Infrastructure\WarehouseCost\MorePlusCostBridgeService;
use Illuminate\Console\Command;
use Throwable;

final class BridgeMorePlusCostsCommand extends Command
{
    protected $signature='inventory:bridge-moreplus-costs {batch : Applied warehouse_bill_import_batches id} {--apply}
        {--expect-source-sha=} {--expect-plan-sha=}
        {--expect-shipping-ready=} {--expect-shipping-review=} {--expect-shipping-excluded=} {--expect-shipping-skip=}
        {--expect-parcel-ready=} {--expect-parcel-review=} {--expect-parcel-excluded=} {--expect-parcel-skip=}
        {--expect-storage-ready=} {--expect-storage-review=} {--expect-storage-excluded=}';
    protected $description='Preview or exact-gated apply of P5 MorePlus warehouse bill cost bridge.';
    public function handle(MorePlusCostBridgeService $service):int
    {
        try{$preview=$service->preview((int)$this->argument('batch'));}
        catch(Throwable $e){$this->line(json_encode(['mode'=>'dry_run','gate'=>'invalid_source','error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return self::FAILURE;}
        if(!(bool)$this->option('apply')){$this->line(json_encode($this->summary($preview),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return self::SUCCESS;}
        if(!$this->applyAllowed()){$this->error('Production --apply requires ERP_MOREPLUS_COST_BRIDGE_PRODUCTION_APPLY_ENABLED=true for this process.');return self::FAILURE;}
        if(app()->environment('production')&&!$this->expectedMatches($preview)){$this->line(json_encode(['mode'=>'apply','gate'=>'blocked_preflight_mismatch','preflight'=>$this->summary($preview)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return self::FAILURE;}
        try{$result=$service->apply((int)$this->argument('batch'));}
        catch(Throwable $e){$this->line(json_encode(['mode'=>'apply','gate'=>'apply_failed','error'=>$e->getMessage(),'preflight'=>$this->summary($preview)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return self::FAILURE;}
        $this->line(json_encode($this->summary($result)+['shipping_created'=>$result['shipping_created']??0,'handling_created'=>$result['handling_created']??0,'storage_batch_id'=>$result['storage_batch_id']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return self::SUCCESS;
    }
    private function applyAllowed():bool { if(app()->environment(['local','testing']))return true;if(!app()->environment('production'))return false;return filter_var(getenv('ERP_MOREPLUS_COST_BRIDGE_PRODUCTION_APPLY_ENABLED')?:'false',FILTER_VALIDATE_BOOL); }
    /** @param array<string,mixed> $p */ private function expectedMatches(array $p):bool
    {
        $source=strtolower(trim((string)$this->option('expect-source-sha')));$plan=strtolower(trim((string)$this->option('expect-plan-sha')));
        if(preg_match('/^[a-f0-9]{64}$/D',$source)!==1||preg_match('/^[a-f0-9]{64}$/D',$plan)!==1||!hash_equals($source,(string)$p['source_checksum'])||!hash_equals($plan,(string)$p['plan_checksum']))return false;
        $checks=['shipping-ready'=>['shipping','ready'],'shipping-review'=>['shipping','review'],'shipping-excluded'=>['shipping','excluded'],'shipping-skip'=>['shipping','skip'],
            'parcel-ready'=>['parcel_outbound','ready'],'parcel-review'=>['parcel_outbound','review'],'parcel-excluded'=>['parcel_outbound','excluded'],'parcel-skip'=>['parcel_outbound','skip'],
            'storage-ready'=>['storage','ready_count'],'storage-review'=>['storage','review_count'],'storage-excluded'=>['storage','excluded_count']];
        foreach($checks as $option=>$path){$expected=$this->expectedInt('expect-'.$option);if($expected===null||$expected!==(int)($p[$path[0]][$path[1]]??0))return false;} return true;
    }
    private function expectedInt(string $key):?int { $v=$this->option($key);return $v!==null&&preg_match('/^\d+$/D',(string)$v)===1?(int)$v:null; }
    /** @param array<string,mixed> $p @return array<string,mixed> */ private function summary(array $p):array
    { return ['mode'=>$p['mode'],'warehouse_bill_batch_id'=>$p['warehouse_bill_batch_id'],'source_checksum'=>$p['source_checksum'],'plan_checksum'=>$p['plan_checksum'],'shipping'=>$p['shipping'],'parcel_outbound'=>$p['parcel_outbound'],'storage'=>$p['storage']]; }
}
