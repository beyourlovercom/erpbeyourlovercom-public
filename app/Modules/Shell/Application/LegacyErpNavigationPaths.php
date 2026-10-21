<?php

namespace App\Modules\Shell\Application;

final class LegacyErpNavigationPaths
{
    public function all(array $navigation): array
    {
        $paths = [];
        $collect = function (array $items) use (&$collect, &$paths): void {
            foreach ($items as $item) {
                if (! empty($item['path'])) {
                    $paths[] = $this->normalize($item['path']);
                }
                if (! empty($item['children'])) {
                    $collect($item['children']);
                }
            }
        };
        foreach ($navigation as $module) {
            $collect($module['children'] ?? []);
        }

        return array_values(array_unique($paths));
    }

    public function normalize(string $path): string
    {
        return '/'.trim(parse_url($path, PHP_URL_PATH) ?: '', '/');
    }
}
