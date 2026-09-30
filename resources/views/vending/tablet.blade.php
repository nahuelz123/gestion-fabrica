<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta http-equiv="refresh" content="20">
    <title>Rapi Burguer · {{ $machine->product->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-red-700 text-white flex items-center justify-center p-6">
    <main class="w-full max-w-3xl text-center">
        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-yellow-400 text-red-900 font-black text-sm uppercase tracking-wider shadow-sm">🍔 Rapi Burguer</div>
        <div class="text-sm uppercase tracking-[0.25em] text-red-100 mt-5 mb-3">Compra rápida</div>
        <h1 class="text-4xl md:text-6xl font-black">{{ $machine->product->name }}</h1>
        <div class="text-4xl md:text-5xl font-black text-yellow-300 mt-4">${{ number_format((float)$machine->sale_price, 0, ',', '.') }}</div>

        @if(!$machine->isReady())
            <div class="mt-10 bg-white text-gray-900 border-4 border-yellow-400 rounded-3xl p-10 shadow-2xl">
                <div class="text-2xl font-bold">Pago temporalmente no disponible</div>
                <p class="text-gray-600 mt-3">El QR se está configurando. Esta pantalla se actualiza automáticamente.</p>
            </div>
        @elseif(!$activeOrder)
            <div class="mt-10 bg-white text-gray-900 border-4 border-yellow-400 rounded-3xl p-10 shadow-2xl">
                <div class="text-2xl font-bold">Preparando el próximo cobro…</div>
                <p class="text-gray-600 mt-3">Esperá unos segundos. Esta pantalla se actualiza automáticamente.</p>
            </div>
        @else
            <div class="mt-8 bg-white rounded-3xl p-6 md:p-8 inline-block shadow-2xl ring-4 ring-yellow-400">
                <img src="{{ $machine->mercadopago_qr_image_url }}" alt="QR Mercado Pago" class="w-72 h-72 md:w-96 md:h-96 object-contain mx-auto">
            </div>
            <div class="mt-7 text-xl md:text-2xl font-bold">1. Escaneá el QR con Mercado Pago</div>
            <div class="mt-2 text-red-100 text-lg">2. Verificá el importe y pagá</div>
            <div class="mt-2 text-red-100 text-lg">3. Retirá y calentá tu hamburguesa</div>
            <div class="mt-6 inline-flex items-center px-4 py-2 rounded-full bg-white text-green-700 font-semibold text-sm">✓ Pago directo al comercio</div>
        @endif

        <div class="mt-10 text-xs text-red-100">Máquina {{ $machine->code }} · {{ $machine->partner->name }}</div>
    </main>
</body>
</html>
