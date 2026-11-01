<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Bench' }}, Packing bench</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-ground text-ink antialiased">
    <header class="bg-slab text-white">
        <div class="flex flex-wrap items-center gap-x-8 gap-y-1 px-4 py-2 lg:px-6">
            <a href="{{ route('bench') }}" wire:navigate class="text-lg font-semibold">Packing bench</a>
            <nav class="flex flex-wrap gap-1">
                @foreach (['bench' => 'Bench', 'orders' => 'Orders', 'stock' => 'Stock', 'board' => 'Board', 'events' => 'Event log'] as $route => $label)
                    <a href="{{ route($route) }}" wire:navigate
                       @class(['rounded-[3px] px-3 py-1', 'bg-white text-ink font-medium' => request()->routeIs($route.'*'), 'text-[#d3d7dc] hover:bg-[#3b434c]' => ! request()->routeIs($route.'*')])>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
    </header>
    <livewire:board-status />
    <main class="px-4 py-4 lg:px-6">
        {{ $slot }}
    </main>
</body>
</html>
