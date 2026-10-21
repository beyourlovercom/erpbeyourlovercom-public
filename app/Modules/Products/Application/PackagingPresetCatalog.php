<?php

namespace App\Modules\Products\Application;

final class PackagingPresetCatalog
{
    public function all(): array
    {
        $items = [];
        foreach ((array) config('packaging_presets', []) as $key => $preset) {
            $items[] = [
                'key' => $key,
                'label' => $preset['label'],
                'summary' => $preset['summary'],
                'materials' => $preset['materials'] ?? [],
                'steps' => array_map(fn (array $step, int $i) => ['position'=>$i+1,'title'=>$step[0],'instruction'=>$step[1]], $preset['steps'] ?? [], array_keys($preset['steps'] ?? [])),
                'tips' => $preset['tips'] ?? [],
                'default_image_url' => $this->illustration($key, $preset['label']),
            ];
        }
        return $items;
    }

    public function keys(): array { return array_keys((array) config('packaging_presets', [])); }
    public function labels(): array { return array_map(fn ($p) => $p['label'], (array) config('packaging_presets', [])); }
    public function get(?string $key): ?array { return collect($this->all())->firstWhere('key', $key); }

    public function guide(?string $key): ?array
    {
        $preset = $this->get($key);
        if (! $preset) return null;
        return ['id'=>0,'title'=>'标准包装教程','method'=>$preset['label'],'preset_key'=>$preset['key'],'scenario'=>$preset['summary'],'version'=>'global-v1','default_illustration_url'=>$preset['default_image_url'],'rendered_illustration_url'=>null,'rendered_illustration_name'=>null,'materials'=>$preset['materials'],'steps'=>$preset['steps'],'tips'=>$preset['tips'],'examples'=>[]];
    }

    private function illustration(string $key, string $label): string
    {
        $shape = match ($key) {
            'carton' => '<rect x="330" y="120" width="540" height="280" rx="18" fill="#c89451"/><path d="M330 190l270 130 270-130M600 320v80" fill="none" stroke="#9a6b35" stroke-width="12"/>',
            'document_bubble_mailer' => '<rect x="300" y="110" width="600" height="300" rx="22" fill="#e9f1f4" stroke="#b8ccd3" stroke-width="8"/><path d="M320 180h560" stroke="#91aeb8" stroke-width="8" stroke-dasharray="12 10"/>',
            'direct_mailer' => '<rect x="315" y="120" width="570" height="280" rx="60" fill="#eef2f3" stroke="#ccd8dc" stroke-width="8"/><rect x="405" y="165" width="390" height="190" rx="60" fill="#a9cdef"/>',
            default => '<rect x="260" y="110" width="680" height="310" rx="36" fill="#eef7fa" stroke="#d4e8ee" stroke-width="10" stroke-dasharray="6 11"/><rect x="360" y="170" width="480" height="190" rx="70" fill="#9fc9ee" stroke="#6e9fc8" stroke-width="8"/><path d="M330 105v320M870 105v320" stroke="#d3a928" stroke-width="6" stroke-dasharray="14 10"/>',
        };
        $svg='<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="560" viewBox="0 0 1200 560"><rect width="1200" height="560" fill="#ead4af"/>'.$shape.'<rect x="42" y="34" width="520" height="68" rx="18" fill="#fff"/><text x="70" y="78" font-family="sans-serif" font-size="30" font-weight="700" fill="#173342">'.htmlspecialchars($label, ENT_QUOTES).'</text><text x="70" y="520" font-family="sans-serif" font-size="22" fill="#405968">全局公共模板 · 产品专属图片仅作为覆盖</text></svg>';
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
