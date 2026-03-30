@extends('layouts.app')

@section('title', 'Servicios')

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Servicios</h1>
        <a href="{{ route('services.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
            Nuevo Servicio
        </a>
    </div>

    @if($grouped->isEmpty())
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No hay servicios registrados
        </div>
    @else
        <div class="space-y-3">
            @foreach($grouped as $groupName => $services)
                @php $groupId = 'group-' . Str::slug($groupName); @endphp
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                    <button
                        type="button"
                        onclick="toggleGroup('{{ $groupId }}')"
                        class="w-full flex items-center justify-between px-6 py-4 text-left focus:outline-none"
                    >
                        <div class="flex items-center gap-3">
                            <span class="text-base font-semibold text-gray-900 dark:text-white">{{ $groupName }}</span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                                {{ $services->count() }} {{ $services->count() === 1 ? 'servicio' : 'servicios' }}
                            </span>
                        </div>
                        <svg
                            id="icon-{{ Str::slug($groupName) }}"
                            class="h-5 w-5 text-gray-500 dark:text-gray-400 transition-transform duration-200"
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                        >
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>

                    <div id="{{ $groupId }}" class="hidden">
                        <div class="border-t border-gray-200 dark:border-gray-700 overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Nombre</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Endpoint</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Slug</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Activo</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach($services as $service)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                            {{ $service->name }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            @if($service->endpoint)
                                                <a href="{{ $service->endpoint }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 truncate max-w-xs block">
                                                    {{ $service->endpoint }}
                                                </a>
                                            @else
                                                <span class="text-gray-400 dark:text-gray-600">-</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">
                                            {{ $service->slug }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            @if($service->is_active)
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">
                                                    Activo
                                                </span>
                                            @else
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                    Inactivo
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="{{ route('services.show', $service) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 mr-3">Ver</a>
                                            <a href="{{ route('services.edit', $service) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 mr-3">Editar</a>
                                            <form action="{{ route('services.destroy', $service) }}" method="POST" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300" onclick="return confirm('¿Estás seguro?')">Eliminar</button>
                                            </form>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
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
