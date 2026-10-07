<div class="space-y-5">
    @if(session()->has('message'))
        <div class="p-4 bg-green-50 border border-green-200 text-green-800 rounded-xl text-sm">{{ session('message') }}</div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Usuarios</h1>
            <p class="text-sm text-gray-500 mt-1">Dueños y encargado de Rapi Burguer.</p>
        </div>
        <a href="{{ route('users.create') }}" wire:navigate class="px-5 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold text-center">+ Nuevo usuario</a>
    </div>

    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Buscar por nombre, email o teléfono..." class="w-full sm:w-96 rounded-lg border-gray-300 px-3 py-3 border text-sm">

    <div class="bg-white border rounded-xl overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Nombre</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Acceso</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Rol</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Telegram</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Estado</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($users as $user)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $user->name }}</div>
                            @if($user->phone)<div class="text-xs text-gray-500">{{ $user->phone }}</div>@endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $user->email ?: '—' }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex px-2 py-1 rounded {{ $user->isOwner() ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-900' }} text-xs font-semibold">{{ $user->role->label() }}</span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $user->telegram_chat_id ? '✅ Vinculado' : '—' }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex px-2 py-1 rounded {{ $user->isActive() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }} text-xs font-semibold">{{ $user->status->label() }}</span>
                        </td>
                        <td class="px-4 py-3 text-right text-sm">
                            <a href="{{ route('users.edit', $user->id) }}" wire:navigate class="text-red-700 font-medium">Editar</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No hay usuarios.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
</div>
