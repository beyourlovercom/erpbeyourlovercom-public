<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Application\PackagingPresetCatalog;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfileMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ProductPackagingProfileController
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PackagingPresetCatalog $presets) {}

    public function edit(int $product): Response
    {
        $item = Product::query()->findOrFail($product);
        $profile = ProductPackagingProfile::query()->with('media')->where('product_id', $item->id)->first();
        return Inertia::render('Products/Packaging', [
            'product'=>['id'=>$item->id,'sku'=>$item->sku,'name'=>$item->name],
            'packagingProfile'=>$profile ? $this->serialize($profile) : null,
            'presets'=>collect($this->presets->all())->map(fn(array $preset)=>['key'=>$preset['key'],'label'=>$preset['label'],'summary'=>$preset['summary']])->values()->all(),
        ]);
    }

    public function update(Request $request, int $product): RedirectResponse
    {
        $item = Product::query()->findOrFail($product);
        $data = $request->validate([
            'preset_key'=>['nullable','string',Rule::in($this->presets->keys())],
            'method'=>['nullable','string','max:128'],
            'scenario'=>['nullable','string','max:512'],
            'version'=>['nullable','string','max:64'],
            'units_per_carton'=>['nullable','integer','min:1','max:100000'],
        ]);
        $presetKey = $data['preset_key'] ?? null;
        $customMethod = $presetKey ? null : trim((string)($data['method'] ?? ''));
        if (! $presetKey && $customMethod === '') throw ValidationException::withMessages(['method'=>'定制包装方案需要填写方案名称']);

        $profile = ProductPackagingProfile::query()->updateOrCreate(
            ['product_id'=>$item->id],
            ['preset_key'=>$presetKey,'custom_method'=>$customMethod,'scenario'=>$data['scenario'] ?? null,'version'=>$data['version'] ?? null,'units_per_carton'=>$data['units_per_carton'] ?? null,'is_active'=>true],
        );
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), 'product.packaging_profile.updated', $profile::class, $profile->id, after:['product_id'=>$item->id,'preset_key'=>$presetKey,'custom_method'=>$customMethod,'units_per_carton'=>$profile->units_per_carton], reason:'product_packaging_profile_updated');
        return back()->with('success', '产品包装引用已保存');
    }

    public function upload(Request $request, int $product): RedirectResponse
    {
        $item = Product::query()->findOrFail($product);
        $profile = ProductPackagingProfile::query()->where('product_id', $item->id)->first();
        if (! $profile) throw ValidationException::withMessages(['files'=>'请先保存包装方式，再上传产品专属图片']);
        $data = $request->validate([
            'kind'=>['required',Rule::in(['default','rendered','example'])],
            'files'=>['required','array','min:1','max:12'],
            'files.*'=>['required','file','image','mimes:jpeg,jpg,png,webp','max:10240'],
        ]);
        $files = $request->file('files', []);
        if (in_array($data['kind'], ['default','rendered'], true)) $files = array_slice($files, 0, 1);
        $stored=[]; $old=collect();
        try {
            DB::transaction(function () use ($profile,$data,$files,&$stored,&$old): void {
                if (in_array($data['kind'], ['default','rendered'], true)) {
                    $old=$profile->media()->where('kind',$data['kind'])->get();
                    $profile->media()->where('kind',$data['kind'])->delete();
                }
                foreach ($files as $file) {
                    $path=$file->store('products/'.$profile->product_id.'/packaging-profile/'.$profile->id.'/'.$data['kind'],'local');
                    $stored[]=$path;
                    $profile->media()->create(['kind'=>$data['kind'],'disk'=>'local','path'=>$path,'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'sort_order'=>$profile->media()->where('kind',$data['kind'])->count()]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) Storage::disk('local')->delete($path);
            throw $e;
        }
        foreach ($old as $media) Storage::disk($media->disk)->delete($media->path);
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), 'product.packaging_profile.media_uploaded', $profile::class, $profile->id, after:['kind'=>$data['kind'],'count'=>count($files)], reason:'product_packaging_profile_media_uploaded');
        return back()->with('success','包装图片已上传');
    }

    public function destroyMedia(Request $request, ProductPackagingProfileMedia $media): RedirectResponse
    {
        $profile=$media->profile()->firstOrFail();
        $kind=$media->kind;
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), 'product.packaging_profile.media_deleted', $profile::class, $profile->id, after:['kind'=>$kind], reason:'product_packaging_profile_media_deleted');
        return back()->with('success','包装图片已删除');
    }

    public function media(Request $request, ProductPackagingProfileMedia $media)
    {
        $user=$request->user();
        abort_unless($user && ($user->can('products.view') || $user->can('products.operate') || $user->can('inventory.packaging.submit') || $user->can('inventory.packaging.review') || $user->can('inventory.view')),403);
        abort_unless(Storage::disk($media->disk)->exists($media->path),404);
        return Storage::disk($media->disk)->response($media->path,$media->original_name,['Content-Type'=>$media->mime_type,'Cache-Control'=>'private, no-store'],'inline');
    }

    private function serialize(ProductPackagingProfile $profile): array
    {
        $media=$profile->relationLoaded('media') ? $profile->media : $profile->media()->get();
        $one=fn(string $kind)=>$media->firstWhere('kind',$kind);
        $url=fn(?ProductPackagingProfileMedia $m)=>$m ? route('products.packaging.media',$m) : null;
        $preset=$this->presets->get($profile->preset_key);
        return [
            'id'=>$profile->id,
            'preset_key'=>$profile->preset_key,
            'method'=>$preset['label'] ?? $profile->custom_method ?? '',
            'scenario'=>$profile->scenario,
            'version'=>$profile->version,
            'units_per_carton'=>$profile->units_per_carton,
            'inherits_global_template'=>$profile->preset_key !== null,
            'default_image'=>($m=$one('default')) ? ['id'=>$m->id,'name'=>$m->original_name,'url'=>$url($m)] : null,
            'rendered_image'=>($m=$one('rendered')) ? ['id'=>$m->id,'name'=>$m->original_name,'url'=>$url($m)] : null,
            'examples'=>$media->where('kind','example')->values()->map(fn($m)=>['id'=>$m->id,'name'=>$m->original_name,'url'=>$url($m)])->all(),
        ];
    }
}
