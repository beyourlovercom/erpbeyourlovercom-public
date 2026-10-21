<?php

namespace App\Modules\Finance\Experience\Console\Commands;

use App\Modules\Finance\Application\CostMaterializationService;
use Illuminate\Console\Command;

final class MaterializeKolCommissionsCommand extends Command
{
    protected $signature = 'finance:materialize-kol-commission
        {--order= : Materialize one order ID}
        {--limit=10000 : Maximum operational orders to inspect}
        {--apply : Persist eligible AffiliateWP commission cost}
        {--expect-seen= : Required Production preflight count}
        {--expect-apply= : Required Production apply count}
        {--expect-noop= : Required Production noop count}
        {--expect-blocked= : Required Production blocked count}
        {--expect-fx-freeze= : Required Production transaction FX freeze count}';

    protected $description = 'Preview or gated-apply P8 AffiliateWP KOL commission cost.';

    public function handle(CostMaterializationService $service): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_ACCOUNTING_KOL_COMMISSION_PRODUCTION_APPLY_ENABLED=true for this process.');
            return self::FAILURE;
        }
        $orderClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\Order';
        $query = $orderClass::query()->orderBy('id');
        if ($this->option('order') !== null) $query->whereKey($this->option('order'));
        else $query->where('order_kind','customer')->whereDoesntHave('externalReferences', fn($refs)=>$refs->where('provider','historical_logistics'))->limit(max(1,min(10000,(int)$this->option('limit'))));
        $orders=$query->get(); $preflight=$this->emptySummary('dry_run');
        foreach($orders as $order) $this->summarizePreview($preflight,$service->previewKolCommissionGate($order));
        if(!$apply){$this->line(json_encode($preflight,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return self::SUCCESS;}
        if(app()->environment('production')&&!$this->expectedCountsMatch($preflight)){$this->line(json_encode(['mode'=>'apply','gate'=>'blocked_preflight_mismatch','preflight'=>$preflight],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return self::FAILURE;}
        $summary=$this->emptySummary('apply');$summary['fx_freeze_orders']=$preflight['fx_freeze_orders'];
        foreach($orders as $order){$result=$service->applyKolCommissionGate($order);$summary['seen']++;if($result['applied']!==[])$summary['applied']++;elseif($result['noop']!==[])$summary['noop']++;else $this->countBlocked($summary,$result['blocked'][0]??['blocker'=>'blocked']);}
        $this->line(json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return self::SUCCESS;
    }
    private function applyAllowed():bool{if(app()->environment(['local','testing']))return true;if(!app()->environment('production'))return false;return filter_var(getenv('ERP_ACCOUNTING_KOL_COMMISSION_PRODUCTION_APPLY_ENABLED')?:'false',FILTER_VALIDATE_BOOL);}
    /** @return array<string,mixed> */ private function emptySummary(string $mode):array{return ['mode'=>$mode,'seen'=>0,'applied'=>0,'noop'=>0,'blocked_total'=>0,'blocked'=>[],'fx_freeze_orders'=>0];}
    /** @param array<string,mixed> $s @param array<string,mixed> $p */ private function summarizePreview(array &$s,array $p):void{$s['seen']++;$c=$p['candidates'][0]??['materialization_status'=>'blocked','blocker'=>'blocked'];$status=(string)($c['materialization_status']??'blocked');if($status==='apply'){$s['applied']++;if(($c['posting_fx']['freeze_transaction_fx']??false)===true)$s['fx_freeze_orders']++;}elseif($status==='noop')$s['noop']++;else $this->countBlocked($s,$c);}
    /** @param array<string,mixed> $s */ private function expectedCountsMatch(array $s):bool{$seen=$this->expectedInt('expect-seen');$apply=$this->expectedInt('expect-apply');$noop=$this->expectedInt('expect-noop');$blocked=$this->expectedInt('expect-blocked');$fx=$this->expectedInt('expect-fx-freeze');return !in_array(null,[$seen,$apply,$noop,$blocked,$fx],true)&&$s['seen']===$seen&&$s['applied']===$apply&&$s['noop']===$noop&&$s['blocked_total']===$blocked&&$s['fx_freeze_orders']===$fx;}
    private function expectedInt(string $o):?int{$v=$this->option($o);return $v!==null&&preg_match('/^\d+$/D',(string)$v)===1?(int)$v:null;}
    /** @param array<string,mixed> $s @param array<string,mixed> $r */ private function countBlocked(array &$s,array $r):void{$b=(string)($r['blocker']??'blocked');$s['blocked_total']++;$s['blocked'][$b]=($s['blocked'][$b]??0)+1;ksort($s['blocked']);}
}
