<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/foundation.css', 'resources/css/shell.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    @inertia
</body>
</html>
