@extends('layouts.appAdmin')

@section('title', 'Asesorias Fiscales')

@section('content')
<style>
    .df-card { padding: 12px 16px; margin-bottom: 8px; }
    .df-card h6 { font-size: 11px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.5px; }
    .df-card .val { font-size: 20px; font-weight: 700; margin: 0; }
    .df-table { font-size: 12px; }
    .df-table th, .df-table td { padding: 6px 8px !important; vertical-align: middle; }
    .df-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; }
    .df-badge { font-size: 10px; padding: 2px 6px; }
    .df-btn-sm { font-size: 11px; padding: 2px 8px; }
</style>

<div class="container-fluid py-2">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fw-bold mb-0" style="font-size: 16px;">
            <i class="fas fa-building me-1" style="color: #0891b2;"></i>Asesorias Fiscales
        </h5>
        <a href="{{ route('admin.asesorias.create') }}" class="btn df-btn-sm" style="background:#0891b2;color:#fff;">
            <i class="fas fa-plus me-1"></i>Nueva Asesoria
        </a>
    </div>

    {{-- Table --}}
    <div class="card shadow-sm border-0">
        <div class="card-body py-2 px-3">
            <table class="table table-hover mb-0 df-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Frecuencia</th>
                        <th>Opciones Envio</th>
                        <th>Activa</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($asesorias as $asesoria)
                    <tr>
                        <td class="fw-semibold">{{ $asesoria->nombre }}</td>
                        <td>{{ $asesoria->email }}</td>
                        <td><span class="badge bg-light text-dark df-badge">{{ ucfirst($asesoria->frecuencia) }}</span></td>
                        <td>
                            @if($asesoria->enviar_diario_caja)<span class="badge bg-info df-badge me-1">Diario</span>@endif
                            @if($asesoria->enviar_facturas_emitidas)<span class="badge bg-info df-badge me-1">Emitidas</span>@endif
                            @if($asesoria->enviar_facturas_recibidas)<span class="badge bg-info df-badge me-1">Recibidas</span>@endif
                            @if($asesoria->enviar_zip_pdfs)<span class="badge bg-info df-badge">ZIP</span>@endif
                        </td>
                        <td>
                            @if($asesoria->activo)
                                <span class="badge bg-success df-badge">Activa</span>
                            @else
                                <span class="badge bg-secondary df-badge">Inactiva</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.asesorias.edit', $asesoria->id) }}" class="btn btn-outline-primary df-btn-sm" title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button class="btn btn-outline-success df-btn-sm" onclick="enviarAhora({{ $asesoria->id }})" title="Enviar Ahora">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                            <form action="{{ route('admin.asesorias.destroy', $asesoria->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Eliminar esta asesoria?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger df-btn-sm" title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center py-3 text-muted">No hay asesorias configuradas.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function enviarAhora(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Enviar informe ahora?',
            text: 'Se generara y enviara el informe del trimestre actual.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0891b2',
            confirmButtonText: 'Enviar',
            cancelButtonText: 'Cancelar'
        }).then(function(result) {
            if (result.isConfirmed) {
                ejecutarEnvio(id);
            }
        });
    } else if (confirm('Enviar informe del trimestre actual a esta asesoria?')) {
        ejecutarEnvio(id);
    }
}

function ejecutarEnvio(id) {
    // Show loading
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Generando informe...', text: 'Esto puede tardar unos segundos.', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
    }

    fetch('/admin/asesorias/' + id + '/enviar-ahora', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json'
        }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: data.success ? 'success' : 'error',
                title: data.success ? 'Enviado' : 'Error',
                text: data.message,
                timer: data.success ? 3000 : undefined,
                showConfirmButton: !data.success
            });
        } else {
            alert(data.message);
        }
    })
    .catch(function(err) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error de conexion: ' + err.message });
        } else {
            alert('Error: ' + err.message);
        }
    });
}
</script>
@endsection
