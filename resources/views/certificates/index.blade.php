@extends('layouts.app')

@section('title', 'Certificados')

@section('content')
<div class="px-4 sm:px-0">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Certificados</h1>
        <a href="{{ route('certificates.create') }}" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
            Nuevo Certificado
        </a>
    </div>

    @if($grouped->isEmpty())
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
            No hay certificados registrados
        </div>
    @else
        <div class="space-y-3">
            @foreach($grouped as $organization => $certificates)
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                {{-- Cabecera del grupo --}}
                <button
                    type="button"
                    onclick="toggleGroup('group-{{ Str::slug($organization) }}')"
                    class="w-full flex items-center justify-between px-6 py-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-base font-semibold text-gray-900 dark:text-white">{{ $organization }}</span>
                        <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">
                            {{ $certificates->count() }} {{ $certificates->count() === 1 ? 'certificado' : 'certificados' }}
                        </span>
                        @php
                            $expired = $certificates->filter(fn($c) => $c->isExpired())->count();
                            $active  = $certificates->filter(fn($c) => $c->isValid())->count();
                        @endphp
                        @if($expired > 0)
                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">
                                {{ $expired }} expirado{{ $expired > 1 ? 's' : '' }}
                            </span>
                        @endif
                    </div>
                    <svg id="icon-{{ Str::slug($organization) }}" class="w-5 h-5 text-gray-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                {{-- Tabla del grupo --}}
                <div id="group-{{ Str::slug($organization) }}" class="hidden">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Nombre</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Clave</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Válido hasta</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Estado</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($certificates as $certificate)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                    {{ $certificate->name }}
                                    @if($certificate->is_becario ?? false)
                                        <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800 dark:bg-amber-800 dark:text-amber-100">Becario</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">
                                    {{ Str::limit($certificate->certificate_key, 20) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    @if($certificate->never_expires)
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100">Nunca expira</span>
                                    @elseif($certificate->valid_until)
                                        {{ $certificate->valid_until->format('d/m/Y') }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($certificate->isValid())
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">Activo</span>
                                    @elseif($certificate->isNotYetValid())
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100">No válido aún</span>
                                    @elseif($certificate->isExpired())
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-800 dark:text-red-100">Expirado</span>
                                    @else
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-100">Inactivo</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    @if($certificate->x509_certificate)
                                        <a href="{{ route('certificates.download', ['certificate' => $certificate, 'format' => 'pem']) }}" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 mr-3" title="Descargar PEM">⬇</a>
                                    @else
                                        <a href="{{ route('certificates.download', ['certificate' => $certificate, 'format' => 'json']) }}" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300 mr-3" title="Descargar JSON">⬇</a>
                                    @endif
                                    <a href="{{ route('certificates.show', $certificate) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 mr-3">Ver</a>
                                    <a href="{{ route('certificates.edit', $certificate) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300 mr-3">Editar</a>
                                    <form action="{{ route('certificates.destroy', $certificate) }}" method="POST" class="inline">
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

// Abrir el primer grupo por defecto
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
