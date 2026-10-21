<?php

namespace App\Modules\Guides\Application;

use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;

final class MarkdownContentRenderer
{
    /** @return array{html:string,toc:list<array{id:string,label:string}>} */
    public function render(string $markdown): array
    {
        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $html = (string) $converter->convert($markdown);
        $toc = [];
        $used = [];
        $html = preg_replace_callback('/<h2>(.*?)<\/h2>/si', function (array $match) use (&$toc, &$used): string {
            $label = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $base = Str::slug($label) ?: 'section';
            $count = ($used[$base] ?? 0) + 1;
            $used[$base] = $count;
            $id = $count === 1 ? $base : $base.'-'.$count;
            $toc[] = ['id' => $id, 'label' => $label];
            return '<h2 id="'.e($id).'">'.$match[1].'</h2>';
        }, $html) ?? $html;

        return ['html' => $html, 'toc' => $toc];
    }
}
