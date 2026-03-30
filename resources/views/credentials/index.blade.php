@extends('layouts.app')
@section('title', 'Credenciales')
@section('content')
<div class="px-4 sm:px-0">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Credenciales</h1>
        <a href="{{ route('credentials.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
            Nueva Credencial
        </a>
    </div>

    @if($grouped->isEmpty())
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
            No hay credenciales registradas
        </div>
    @else
        <div class="space-y-3">
            @foreach($grouped as $websiteName => $credentials)
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <button
                    type="button"
                    onclick="toggleGroup('group-{{ Str::slug($websiteName) }}')"
                    class="w-full flex items-center justify-between px-6 py-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ $websiteName }}</span>
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">
                            {{ $credentials->count() }} {{ $credentials->count() === 1 ? 'credencial' : 'credenciales' }}
                        </span>
                    </div>
                    <svg id="icon-{{ Str::slug($websiteName) }}" class="w-5 h-5 text-gray-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div id="group-{{ Str::slug($websiteName) }}" class="hidden">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Usuario</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">URL Pattern</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Certificados</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Estado</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($credentials as $credential)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                    {{ $credential->username }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono text-xs">
                                    {{ $credential->website_url_pattern ?? '-' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    {{ $credential->certificates->count() }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($credential->is_active)
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Activa</span>
                                    @else
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Inactiva</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="{{ route('credentials.show', $credential) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 mr-3">Ver</a>
                                    <a href="{{ route('credentials.edit', $credential) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 mr-3">Editar</a>
                                    <form action="{{ route('credentials.destroy', $credential) }}" method="POST" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('¿Estás seguro?')">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>

<script>
function toggleGroup(id) {
    const content = document.getElementById(id);
    const icon = document.getElementById('icon-' + id.replace('group-', ''));
    const isHidden = content.classList.contains('hidden');
    content.classList.toggle('hidden', !isHidden);
    icon.style.transform = isHidden ? 'rotate(180deg)' : '';
}
document.addEventListener('DOMContentLoaded', function () {
    const first = document.querySelector('[id^="group-"]');
    if (first) {
        const id = first.id;
        const icon = document.getElementById('icon-' + id.replace('group-', ''));
        first.classList.remove('hidden');
        if (icon) icon.style.transform = 'rotate(180deg)';
    }
});
</script>
@endsection
