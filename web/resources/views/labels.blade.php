<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Barcode labels, Packing bench</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white p-6 text-ink">
    <div class="no-print mb-6 flex items-center gap-3">
        <a href="{{ route('stock') }}" class="btn">Back to stock</a>
        <button class="btn btn-act" onclick="print()">Print</button>
    </div>
    <div class="grid max-w-4xl gap-8 sm:grid-cols-2">
        @foreach ($bins() as $bin)
            @php([$bars, $width] = \App\Support\Code39::bars($bin->sku))
            <div>
                <svg viewBox="0 0 {{ $width }} 60" class="h-24 w-full" preserveAspectRatio="none" role="img" aria-label="Barcode {{ $bin->sku }}">
                    @foreach ($bars as [$x, $bar])
                        <rect x="{{ $x }}" y="0" width="{{ $bar }}" height="60" fill="#000"/>
                    @endforeach
                </svg>
                <p class="mt-1 text-lg font-semibold">{{ $bin->sku }}</p>
                <p class="text-muted">{{ $bin->name }}, bin {{ $bin->code }}</p>
            </div>
        @endforeach
    </div>
</body>
</html>
