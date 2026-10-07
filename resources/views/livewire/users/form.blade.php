<div class="max-w-2xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $userId ? 'Editar usuario' : 'Nuevo usuario' }}</h1>
        <p class="text-sm text-gray-500 mt-1">El encargado sólo ve stock y producción. Los dueños administran todo.</p>
        <a href="{{ route('users.index') }}" wire:navigate class="inline-block mt-2 text-sm text-red-700">← Volver</a>
    </div>

    <form wire:submit="save" class="space-y-5">
        <div class="bg-white border border-gray-200 rounded-2xl p-5 sm:p-6 space-y-5">
            <div>
                <label class="text-sm font-semibold text-gray-800">Nombre *</label>
                <input wire:model="name" class="mt-1 w-full border rounded-lg px-3 py-3">
                @error('name')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-sm font-semibold text-gray-800">Email *</label>
                <input wire:model="email" type="email" autocomplete="email" class="mt-1 w-full border rounded-lg px-3 py-3">
                @error('email')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-sm font-semibold text-gray-800">Teléfono</label>
                <input wire:model="phone" inputmode="tel" class="mt-1 w-full border rounded-lg px-3 py-3">
                @error('phone')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-semibold text-gray-800">Rol *</label>
                    <select wire:model="role" class="mt-1 w-full border rounded-lg px-3 py-3">
                        @foreach($roles as $item)
                            <option value="{{ $item->value }}">{{ $item->label() }}</option>
                        @endforeach
                    </select>
                    @error('role')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-semibold text-gray-800">Estado *</label>
                    <select wire:model="status" class="mt-1 w-full border rounded-lg px-3 py-3">
                        @foreach($statuses as $item)
                            <option value="{{ $item->value }}">{{ $item->label() }}</option>
                        @endforeach
                    </select>
                    @error('status')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label class="text-sm font-semibold text-gray-800">Telegram Chat ID</label>
                <input wire:model="telegram_chat_id" placeholder="Opcional" class="mt-1 w-full border rounded-lg px-3 py-3">
                <p class="text-xs text-gray-500 mt-1">Sólo si este usuario va a operar el bot de Telegram.</p>
                @error('telegram_chat_id')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-semibold text-gray-800">{{ $userId ? 'Nueva contraseña' : 'Contraseña *' }}</label>
                    <input wire:model="password" type="password" autocomplete="new-password" class="mt-1 w-full border rounded-lg px-3 py-3">
                    @error('password')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="text-sm font-semibold text-gray-800">Repetir contraseña</label>
                    <input wire:model="password_confirmation" type="password" autocomplete="new-password" class="mt-1 w-full border rounded-lg px-3 py-3">
                </div>
            </div>

            @if($userId)
                <p class="text-xs text-gray-500">Dejá la contraseña vacía si no querés cambiarla.</p>
            @endif
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="{{ route('users.index') }}" wire:navigate class="px-5 py-3 border rounded-lg text-center">Cancelar</a>
            <button type="submit" wire:loading.attr="disabled" class="px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold disabled:opacity-50">
                {{ $userId ? 'Guardar cambios' : 'Crear usuario' }}
            </button>
        </div>
    </form>
</div>
