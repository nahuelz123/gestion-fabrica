<div class="bg-white shadow-lg rounded-2xl p-8 border border-red-100">
    <div class="text-center mb-7">
        <div class="mx-auto w-16 h-16 rounded-full bg-red-600 text-white flex items-center justify-center text-3xl shadow-sm">🍔</div>
        <h1 class="text-3xl font-black text-red-600 mt-4">Rapi Burguer</h1>
        <p class="text-gray-500 mt-1">Gestión de fábrica</p>
        <div class="w-16 h-1 bg-yellow-400 rounded-full mx-auto mt-3"></div>
    </div>

    <form wire:submit="authenticate" class="space-y-4">
        <div>
            <label for="login" class="block text-sm font-medium text-gray-700">Email o teléfono</label>
            <input wire:model="login" type="text" id="login" placeholder="Ingresá tu usuario" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm px-4 py-3 border" autofocus>
            @error('login')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Contraseña</label>
            <input wire:model="password" type="password" id="password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-red-500 focus:ring-red-500 sm:text-sm px-4 py-3 border">
            @error('password')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex items-center">
            <input wire:model="remember" type="checkbox" id="remember" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
            <label for="remember" class="ml-2 text-sm text-gray-600">Recordarme</label>
        </div>

        <button type="submit" class="w-full flex justify-center py-3 px-4 rounded-lg shadow-sm text-sm font-bold text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition" wire:loading.attr="disabled" wire:loading.class="opacity-50 cursor-not-allowed">
            <span wire:loading.remove>Ingresar</span>
            <span wire:loading>Ingresando...</span>
        </button>
    </form>
</div>
