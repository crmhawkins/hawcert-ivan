@extends('layouts.app')

@section('title', 'Acceso a Servidores SSH')

@section('content')
<div class="px-4 sm:px-0">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Acceso a Servidores SSH</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Genera una clave de acceso temporal de un solo uso para conectarte a los servidores.
        </p>
    </div>

    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-700 dark:text-red-400 text-sm">
            {{ session('error') }}
        </div>
    @endif

    @if(!$certificate)
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-6 text-center">
            <svg class="mx-auto h-12 w-12 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.538-1.333-3.308 0L3.732 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-yellow-800 dark:text-yellow-300">Sin certificado activo</h3>
            <p class="mt-1 text-sm text-yellow-600 dark:text-yellow-400">No tienes un certificado válido asociado a tu cuenta.</p>
        </div>
    @elseif($servers->isEmpty())
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-8 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">Sin servidores asignados</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                El certificado <strong>{{ $certificate->name }}</strong> no tiene acceso a ningún servidor SSH todavía.
            </p>
        </div>
    @else
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-lg p-4 mb-6 flex items-start gap-3">
            <svg class="h-5 w-5 text-blue-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="text-sm text-blue-700 dark:text-blue-300">
                Accediendo como <strong>{{ $certificate->name }}</strong>.
                Las claves generadas son de <strong>un solo uso</strong> y caducan en <strong>10 minutos</strong>.
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($servers as $server)
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-5 flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <div class="flex-shrink-0 w-10 h-10 bg-gray-900 dark:bg-gray-700 rounded-lg flex items-center justify-content-center">
                        <svg class="w-6 h-6 text-green-400 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white text-sm">{{ $server->name }}</h3>
                        @if($server->description)
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $server->description }}</p>
                        @endif
                    </div>
                </div>

                <div class="text-xs text-gray-500 dark:text-gray-400 font-mono bg-gray-50 dark:bg-gray-900 rounded px-3 py-2">
                    <div>ssh {{ $server->ssh_user ?? 'admin' }}@{{ $server->ssh_host }}</div>
                    @if($server->ssh_port != 22)
                        <div class="text-gray-400">Puerto: {{ $server->ssh_port }}</div>
                    @endif
                </div>

                <form method="POST" action="{{ route('ssh.token', $server) }}" class="mt-auto">
                    @csrf
                    <button type="submit"
                        class="w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md shadow-sm transition-colors">
                        Obtener clave de acceso
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
