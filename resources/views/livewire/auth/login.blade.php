<div class="bg-white shadow-md rounded-lg p-8">
    <div class="text-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">🏭 Gestión Fábrica</h1>
        <p class="text-gray-500 mt-1">Ingresá con tu email o teléfono</p>
    </div>

    <form wire:submit="authenticate" class="space-y-4">
        <div>
            <label for="login" class="block text-sm font-medium text-gray-700">Email o Teléfono</label>
            <input
                wire:model="login"
                type="text"
                id="login"
                placeholder="admin@fabrica.com o 1111111111"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm px-3 py-2 border"
                autofocus
            >
            @error('login')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Contraseña</label>
            <input
                wire:model="password"
                type="password"
                id="password"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm px-3 py-2 border"
            >
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center">
            <input wire:model="remember" type="checkbox" id="remember" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
            <label for="remember" class="ml-2 text-sm text-gray-600">Recordarme</label>
        </div>

        <button
            type="submit"
            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition"
            wire:loading.attr="disabled"
            wire:loading.class="opacity-50 cursor-not-allowed"
        >
            <span wire:loading.remove>Ingresar</span>
            <span wire:loading>Ingresando...</span>
        </button>
    </form>
</div>
