@extends('layouts.app')

@section('title', 'Clave de Acceso SSH — {{ $service->name }}')

@section('content')
<div class="px-4 sm:px-0 max-w-2xl mx-auto">

    {{-- Legal warning header --}}
    <div class="bg-amber-50 dark:bg-amber-900/20 border-2 border-amber-400 dark:border-amber-600 rounded-xl p-5 mb-6">
        <div class="flex items-start gap-3">
            <svg class="h-6 w-6 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.538-1.333-3.308 0L3.732 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div class="text-sm text-amber-800 dark:text-amber-300">
                <p class="font-semibold text-base mb-2">Aviso legal — Acceso a servidor SSH</p>
                <p class="mb-2">
                    Esta clave de acceso ha sido generada exclusivamente para
                    <strong>{{ $certificate->name }}</strong> ({{ $certificate->email }}).
                    Es de uso estrictamente personal e intransferible.
                </p>
                <p class="mb-2">
                    Todas las acciones realizadas durante la sesión iniciada con esta clave quedan
                    registradas y son <strong>responsabilidad exclusiva del titular del certificado</strong>.
                    Compartir esta clave con terceros puede constituir una infracción de la política de
                    seguridad de la organización.
                </p>
                <p class="font-semibold">
                    Al usar esta clave aceptas estas condiciones.
                </p>
            </div>
        </div>
    </div>

    {{-- OTP card --}}
    <div class="bg-white dark:bg-gray-800 shadow-lg rounded-xl p-6">
        <h1 class="text-xl font-bold text-gray-900 dark:text-white mb-1">Clave de acceso generada</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
            Servidor: <strong class="text-gray-900 dark:text-white">{{ $service->name }}</strong>
            &nbsp;·&nbsp;
            Válida durante <strong>10 minutos</strong>
            &nbsp;·&nbsp;
            <span class="text-red-600 dark:text-red-400 font-semibold">Un solo uso</span>
        </p>

        {{-- The OTP itself --}}
        <div class="bg-gray-900 rounded-xl p-6 text-center mb-6">
            <p class="text-xs text-gray-400 mb-3 uppercase tracking-widest">Tu contraseña temporal</p>
            <div id="otp-display"
                 class="text-3xl font-mono font-bold tracking-[0.25em] text-green-400 select-all"
                 title="Haz clic para copiar">
                {{ $token }}
            </div>
            <button onclick="copyOtp()"
                    class="mt-4 px-4 py-1.5 text-xs text-gray-400 hover:text-white border border-gray-600 hover:border-gray-400 rounded-full transition-colors">
                📋 Copiar al portapapeles
            </button>
            <p id="copy-ok" class="text-xs text-green-400 mt-2 hidden">¡Copiado!</p>
        </div>

        {{-- SSH connection instructions --}}
        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4 mb-6">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Cómo conectarse</p>
            <code class="text-sm text-gray-800 dark:text-gray-200 font-mono block">
                ssh {{ ($service->ssh_user ?? 'admin') . '@' . $service->ssh_host }}{{ ($service->ssh_port ?? 22) != 22 ? ' -p ' . $service->ssh_port : '' }}
            </code>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                Cuando el sistema pida contraseña, introduce la clave de arriba.
            </p>
        </div>

        {{-- Security note --}}
        <div class="text-xs text-gray-500 dark:text-gray-400 border-t border-gray-200 dark:border-gray-700 pt-4 space-y-1">
            <p>🔒 Esta clave <strong>no se puede volver a mostrar</strong>. Si la pierdes, genera una nueva.</p>
            <p>⏱️ Caduca a las <strong>{{ now()->addMinutes(10)->format('H:i') }}</strong> (hora del servidor).</p>
            <p>📍 Solicitud registrada desde <strong>{{ request()->ip() }}</strong>.</p>
        </div>

        <div class="mt-6 flex gap-3">
            <a href="{{ route('ssh.index') }}"
               class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-600">
                ← Volver a servidores
            </a>
        </div>
    </div>
</div>

<script>
function copyOtp() {
    const otp = document.getElementById('otp-display').textContent.trim();
    navigator.clipboard.writeText(otp).then(() => {
        document.getElementById('copy-ok').classList.remove('hidden');
        setTimeout(() => document.getElementById('copy-ok').classList.add('hidden'), 2500);
    });
}
</script>
@endsection
