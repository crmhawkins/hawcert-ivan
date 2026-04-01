@extends('layouts.app')

@section('title', 'Editar Servicio')

@section('content')
<div class="px-4 sm:px-0">
    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-6">Editar Servicio</h1>

    <form action="{{ route('services.update', $service) }}" method="POST" class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nombre</label>
                <input type="text" name="name" id="name" required value="{{ old('name', $service->name) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            </div>

            <div>
                <label for="slug" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Slug</label>
                <input type="text" name="slug" id="slug" value="{{ old('slug', $service->slug) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white font-mono">
            </div>

            <div>
                <label for="endpoint" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Endpoint</label>
                <input type="url" name="endpoint" id="endpoint" value="{{ old('endpoint', $service->endpoint) }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Descripción</label>
                <textarea name="description" id="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">{{ old('description', $service->description) }}</textarea>
            </div>

            {{-- Service type selector --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tipo de servicio</label>
                <div class="mt-2 flex gap-4">
                    <label class="inline-flex items-center">
                        <input type="radio" name="service_type" value="web"
                               {{ old('service_type', $service->service_type ?? 'web') === 'web' ? 'checked' : '' }}
                               class="text-indigo-600" onchange="toggleSshFields(this.value)">
                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">🌐 Web / Aplicación</span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="service_type" value="ssh"
                               {{ old('service_type', $service->service_type ?? 'web') === 'ssh' ? 'checked' : '' }}
                               class="text-indigo-600" onchange="toggleSshFields(this.value)">
                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">🖥️ Servidor SSH</span>
                    </label>
                </div>
            </div>

            {{-- SSH fields (shown only when type=ssh) --}}
            <div id="ssh-fields" class="{{ old('service_type', $service->service_type ?? 'web') === 'ssh' ? '' : 'hidden' }} grid grid-cols-1 gap-6 sm:grid-cols-2 border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-900">
                <div class="sm:col-span-2">
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-3">Configuración SSH</p>
                </div>
                <div>
                    <label for="ssh_host" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Host / IP del servidor</label>
                    <input type="text" name="ssh_host" id="ssh_host" value="{{ old('ssh_host', $service->ssh_host) }}"
                           placeholder="ej: 82.165.123.45 o servidor.ionos.com"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label for="ssh_port" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Puerto SSH</label>
                    <input type="number" name="ssh_port" id="ssh_port" value="{{ old('ssh_port', $service->ssh_port ?? 22) }}"
                           min="1" max="65535"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>
                <div>
                    <label for="ssh_user" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Usuario SSH compartido</label>
                    <input type="text" name="ssh_user" id="ssh_user" value="{{ old('ssh_user', $service->ssh_user ?? 'admin') }}"
                           placeholder="ej: admin"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Usuario Linux compartido (se registra quién accedió mediante certificado)</p>
                </div>
                <div>
                    <label for="api_secret" class="block text-sm font-medium text-gray-700 dark:text-gray-300">API Secret (opcional)</label>
                    <input type="text" name="api_secret" id="api_secret" value="{{ old('api_secret', $service->api_secret) }}"
                           placeholder="Se genera automáticamente si se deja vacío"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white font-mono text-xs">
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Clave secreta que el servidor SSH incluye al validar el OTP con esta API</p>
                </div>
            </div>

            <div>
                <label class="flex items-center">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $service->is_active) ? 'checked' : '' }} class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Activo</span>
                </label>
            </div>
        </div>

        <div class="mt-6 flex justify-end space-x-3">
            <a href="{{ route('services.index') }}" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                Cancelar
            </a>
            <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">
                Actualizar Servicio
            </button>
        </div>
    </form>
</div>
<script>
function toggleSshFields(type) {
    const sshFields = document.getElementById('ssh-fields');
    if (type === 'ssh') {
        sshFields.classList.remove('hidden');
    } else {
        sshFields.classList.add('hidden');
    }
}
// Init on load
document.addEventListener('DOMContentLoaded', function() {
    const checked = document.querySelector('input[name="service_type"]:checked');
    if (checked) toggleSshFields(checked.value);
});
</script>
@endsection
