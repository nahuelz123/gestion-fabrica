<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta http-equiv="refresh" content="20">
    <title>{{ $machine->product->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gray-950 text-white flex items-center justify-center p-6">
    <main class="w-full max-w-3xl text-center">
        <div class="text-sm uppercase tracking-[0.25em] text-gray-400 mb-3">Compra rápida</div>
        <h1 class="text-4xl md:text-6xl font-black">{{ $machine->product->name }}</h1>
        <div class="text-4xl md:text-5xl font-bold text-green-400 mt-4">${{ number_format((float)$machine->sale_price, 0, ',', '.') }}</div>

        @if($machine->loaded_units <= 0)
            <div class="mt-10 bg-red-950 border border-red-700 rounded-3xl p-10">
                <div class="text-5xl mb-4">🍔</div>
                <div class="text-3xl font-bold">Momentáneamente agotado</div>
                <p class="text-gray-300 mt-3">No realices el pago. Estamos reponiendo la máquina.</p>
            </div>
        @elseif(!$machine->isReady())
            <div class="mt-10 bg-yellow-950 border border-yellow-700 rounded-3xl p-10">
                <div class="text-2xl font-bold">Pago temporalmente no disponible</div>
                <p class="text-gray-300 mt-3">El QR se está configurando. Esta pantalla se actualiza automáticamente.</p>
            </div>
        @elseif(!$activeOrder)
            <div class="mt-10 bg-blue-950 border border-blue-700 rounded-3xl p-10">
                <div class="text-2xl font-bold">Preparando el próximo cobro…</div>
                <p class="text-gray-300 mt-3">Esperá unos segundos. No escanees un QR guardado anteriormente.</p>
            </div>
        @else
            <div class="mt-8 bg-white rounded-3xl p-6 md:p-8 inline-block shadow-2xl">
                <img src="{{ $machine->mercadopago_qr_image_url }}" alt="QR Mercado Pago" class="w-72 h-72 md:w-96 md:h-96 object-contain mx-auto">
            </div>
            <div class="mt-7 text-xl md:text-2xl font-semibold">1. Escaneá el QR con Mercado Pago</div>
            <div class="mt-2 text-gray-300 text-lg">2. Verificá el importe y pagá</div>
            <div class="mt-2 text-gray-300 text-lg">3. Retirá y calentá tu hamburguesa</div>
            <div class="mt-6 inline-flex items-center px-4 py-2 rounded-full bg-green-950 border border-green-700 text-green-300 text-sm">Pago acreditado directamente al comercio</div>
        @endif

        <div class="mt-10 text-xs text-gray-500">Máquina {{ $machine->code }} · {{ $machine->partner->name }}</div>
    </main>
</body>
</html>
