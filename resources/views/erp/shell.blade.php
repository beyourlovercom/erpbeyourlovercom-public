<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page['label'] }} · BYL ERP</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-module="{{ $module }}" data-current-path="{{ $currentPath }}">
<div class="app-shell">
    <nav class="global-rail" aria-label="全局导航">
        <a class="brand-mark" href="/logistics/wait-stock" aria-label="BYL ERP 首页"><span>BYL</span><small>ERP</small></a>
        <div class="rail-items">
            @foreach($navigation as $key => $item)
                <a class="rail-item {{ $module === $key ? 'is-active' : '' }}" href="{{ $item['children'][array_key_first($item['children'])]['path'] }}" title="{{ $item['label'] }}" aria-label="{{ $item['label'] }}">
                    <span class="rail-icon">{{ $item['icon'] }}</span><span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
        <div class="rail-footer"><span class="status-dot"></span><span>本地</span></div>
    </nav>
    <aside class="module-sidebar" id="module-sidebar" aria-label="当前模块导航">
        <div class="module-heading"><div class="module-icon">{{ $currentModule['icon'] }}</div><div><strong>{{ $currentModule['label'] }}</strong><small>工作空间</small></div><button data-sidebar-toggle aria-label="收起模块导航">‹</button></div>
        <label class="search-shell"><span>⌕</span><input type="search" placeholder="搜索菜单" aria-label="搜索菜单"></label>
        <div class="module-menu">
            @foreach($currentModule['children'] as $key => $item)
                @if(!empty($item['group']))
                    <div class="menu-group" data-menu-group="{{ $key }}"><button class="group-toggle" aria-expanded="true"><span>⌄</span>{{ $item['label'] }}</button><div class="group-children">
                        @foreach($item['children'] as $child)<a class="menu-link nested {{ trim($child['path'], '/') === $currentPath ? 'is-active' : '' }}" href="{{ $child['path'] }}">{{ $child['label'] }}</a>@endforeach
                    </div></div>
                @else
                    <a class="menu-link {{ trim($item['path'], '/') === $currentPath ? 'is-active' : '' }}" href="{{ $item['path'] }}">{{ $item['label'] }}</a>
                @endif
            @endforeach
        </div>
    </aside>
    <main class="workspace">
        <header class="topbar"><div class="page-identity" title="{{ $currentModule['label'] }} / {{ $page['label'] }} / {{ $currentPath }}"><span class="identity-module">{{ $currentModule['icon'] }} {{ $currentModule['label'] }}</span><b>/</b><strong>{{ $page['label'] }}</strong><b>/</b><small>{{ $currentPath }}</small></div><div class="topbar-actions"><span class="prototype-badge">LOCAL PROTOTYPE</span><span class="avatar">A</span></div></header>
        <button class="reopen-sidebar" data-sidebar-toggle aria-label="展开模块导航">›</button>
        <section class="content-area" aria-label="工作空间">
            @if($currentPath === 'kol/orders')
                @include('erp.partials.order-list', ['orders' => $orders])
            @elseif(str_starts_with($currentPath, 'kol/orders/'))
                @include('erp.partials.order-detail', ['order' => $order])
            @endif
        </section>
    </main>
</div>
</body></html>
