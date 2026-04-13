@extends('layouts.appAdmin')

@section('title', 'Facturas Recibidas (Gastos)')

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
    .df-filter { font-size: 12px; }
    .df-filter .form-control, .df-filter .form-select { font-size: 12px; padding: 4px 8px; height: 30px; }
    .df-filter .btn { font-size: 12px; padding: 4px 12px; height: 30px; }
    .df-pagination .page-link { font-size: 11px; padding: 2px 8px; }
    .thumb-factura { width: 28px; height: 28px; object-fit: cover; border-radius: 3px; border: 1px solid #e5e7eb; cursor: pointer; }
    .upload-btn { font-size: 10px; padding: 1px 6px; cursor: pointer; }
</style>

<div class="container-fluid py-2">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fw-bold mb-0" style="font-size: 16px;">
            <i class="fas fa-file-invoice me-1" style="color: #0891b2;"></i>Facturas Recibidas (Gastos)
        </h5>
        <a href="{{ route('admin.tesoreria.dashboard') }}" class="btn btn-outline-secondary df-btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Dashboard
        </a>
    </div>

    {{-- Filtros --}}
    <div class="card shadow-sm border-0 mb-2">
        <div class="card-body py-2 px-3 df-filter">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col">
                    <input type="date" name="fecha_desde" class="form-control" value="{{ request('fecha_desde', $fechaDesde ?? '') }}">
                </div>
                <div class="col">
                    <input type="date" name="fecha_hasta" class="form-control" value="{{ request('fecha_hasta', $fechaHasta ?? '') }}">
                </div>
                <div class="col">
                    <select name="categoria_id" class="form-select">
                        <option value="">Todas las categorias</option>
                        @foreach($categorias ?? [] as $cat)
                            <option value="{{ $cat->id }}" {{ request('categoria_id') == $cat->id ? 'selected' : '' }}>{{ $cat->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col">
                    <select name="tiene_factura" class="form-select">
                        <option value="" {{ request('tiene_factura') === null ? 'selected' : '' }}>Todos</option>
                        <option value="1" {{ request('tiene_factura') === '1' ? 'selected' : '' }}>Con factura</option>
                        <option value="0" {{ request('tiene_factura') === '0' ? 'selected' : '' }}>Sin factura</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn df-btn-sm" style="background:#0891b2;color:#fff;">
                        <i class="fas fa-filter"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Tarjetas resumen --}}
    <div class="row g-2 mb-2">
        <div class="col-4">
            <div class="card shadow-sm border-0 df-card" style="border-left:3px solid #0891b2!important;">
                <h6>Total Gastos</h6>
                <p class="val">{{ number_format($totalGastos ?? 0, 2, ',', '.') }} &euro;</p>
                <small class="text-muted" style="font-size:10px;">{{ $numGastos ?? 0 }} registros</small>
            </div>
        </div>
        <div class="col-4">
            <div class="card shadow-sm border-0 df-card" style="border-left:3px solid #10b981!important;">
                <h6>Con Factura</h6>
                <p class="val text-success">{{ $conFactura ?? 0 }}</p>
            </div>
        </div>
        <div class="col-4">
            <div class="card shadow-sm border-0 df-card" style="border-left:3px solid #f59e0b!important;">
                <h6>Sin Factura</h6>
                <p class="val text-warning">{{ $sinFactura ?? 0 }}</p>
            </div>
        </div>
    </div>

    {{-- Tabla --}}
    <div class="card shadow-sm border-0">
        <div class="card-body py-2 px-3">
            <div style="max-height: 500px; overflow-y: auto;">
                <table class="table table-hover mb-0 df-table">
                    <thead style="position:sticky;top:0;background:#f8fafc;z-index:1;">
                        <tr>
                            <th>Fecha</th>
                            <th>Descripcion</th>
                            <th>Categoria</th>
                            <th>Importe</th>
                            <th>Estado</th>
                            <th>Factura</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($gastos ?? [] as $gasto)
                        <tr>
                            <td>{{ $gasto->fecha ? \Carbon\Carbon::parse($gasto->fecha)->format('d/m/Y') : '-' }}</td>
                            <td>{{ Str::limit($gasto->descripcion, 50) }}</td>
                            <td>
                                @if($gasto->categoria)
                                    <span class="badge bg-light text-dark df-badge">{{ $gasto->categoria->nombre }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="fw-bold">{{ number_format(abs($gasto->importe), 2, ',', '.') }} &euro;</td>
                            <td>
                                @php
                                    $estadoConfig = [
                                        1 => ['class' => 'bg-warning text-dark', 'label' => 'Pend.Cat'],
                                        2 => ['class' => 'bg-orange text-white', 'label' => 'Pend.Pago'],
                                        3 => ['class' => 'bg-success', 'label' => 'Pagado'],
                                    ];
                                    $est = $estadoConfig[$gasto->estado_id] ?? ['class' => 'bg-secondary', 'label' => 'N/D'];
                                @endphp
                                <span class="badge {{ $est['class'] }} df-badge"
                                      @if($gasto->estado_id == 2) style="background-color:#f97316!important;" @endif>
                                    {{ $est['label'] }}
                                </span>
                            </td>
                            <td>
                                @if($gasto->factura_foto)
                                    <a href="{{ Storage::url($gasto->factura_foto) }}" target="_blank" title="Ver factura" class="d-inline-flex align-items-center gap-1">
                                        <i class="fas fa-check-circle text-success" style="font-size:14px;"></i>
                                        @if(Str::endsWith(strtolower($gasto->factura_foto), ['.jpg','.jpeg','.png','.webp']))
                                            <img src="{{ Storage::url($gasto->factura_foto) }}" class="thumb-factura" alt="Factura">
                                        @else
                                            <i class="fas fa-file-pdf text-danger" style="font-size:14px;"></i>
                                        @endif
                                    </a>
                                @else
                                    <button class="btn btn-outline-warning upload-btn" onclick="abrirUpload({{ $gasto->id }})" title="Subir factura">
                                        <i class="fas fa-upload"></i> Subir
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-3 text-muted">Sin gastos en el periodo seleccionado.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if(isset($gastos) && $gastos->hasPages())
        <div class="card-footer bg-white py-1 df-pagination">
            {{ $gastos->appends(request()->query())->links() }}
        </div>
        @endif
    </div>
</div>

{{-- Modal Upload Factura --}}
<div class="modal fade" id="modalUpload" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2 px-3" style="background:#0891b2;">
                <h6 class="modal-title text-white mb-0" style="font-size:13px;">
                    <i class="fas fa-upload me-1"></i>Subir Factura
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar" style="font-size:10px;"></button>
            </div>
            <div class="modal-body py-3 px-3">
                <form id="formUploadFactura" enctype="multipart/form-data">
                    <input type="hidden" id="uploadGastoId" name="gasto_id" value="">
                    <div class="mb-2">
                        <label class="form-label" style="font-size:12px;">Archivo (imagen o PDF)</label>
                        <input type="file" class="form-control form-control-sm" id="inputFacturaFile" name="factura"
                               accept="image/*,.pdf" required style="font-size:12px;">
                    </div>
                    <div id="uploadPreview" class="mb-2 text-center" style="display:none;">
                        <img id="previewImg" src="" alt="Preview" style="max-width:100%;max-height:150px;border-radius:4px;">
                    </div>
                    <button type="submit" class="btn w-100" style="background:#0891b2;color:#fff;font-size:12px;padding:6px;">
                        <i class="fas fa-cloud-upload-alt me-1"></i>Subir Factura
                    </button>
                </form>
                <div id="uploadProgress" style="display:none;" class="mt-2">
                    <div class="progress" style="height:6px;">
                        <div class="progress-bar" id="progressBar" style="width:0%;background:#0891b2;"></div>
                    </div>
                    <small class="text-muted" style="font-size:10px;">Subiendo...</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var uploadModal = null;

document.addEventListener('DOMContentLoaded', function() {
    uploadModal = new bootstrap.Modal(document.getElementById('modalUpload'));
});

function abrirUpload(gastoId) {
    document.getElementById('uploadGastoId').value = gastoId;
    document.getElementById('inputFacturaFile').value = '';
    document.getElementById('uploadPreview').style.display = 'none';
    document.getElementById('uploadProgress').style.display = 'none';
    uploadModal.show();
}

// Preview image
document.getElementById('inputFacturaFile').addEventListener('change', function(e) {
    var file = e.target.files[0];
    if (file && file.type.startsWith('image/')) {
        var reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('previewImg').src = ev.target.result;
            document.getElementById('uploadPreview').style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        document.getElementById('uploadPreview').style.display = 'none';
    }
});

// AJAX upload
document.getElementById('formUploadFactura').addEventListener('submit', function(e) {
    e.preventDefault();

    var gastoId = document.getElementById('uploadGastoId').value;
    var fileInput = document.getElementById('inputFacturaFile');

    if (!fileInput.files.length) {
        alert('Selecciona un archivo.');
        return;
    }

    var formData = new FormData();
    formData.append('factura', fileInput.files[0]);
    formData.append('_token', '{{ csrf_token() }}');

    document.getElementById('uploadProgress').style.display = 'block';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/tesoreria/gastos/' + gastoId + '/subir-factura', true);
    xhr.setRequestHeader('X-CSRF-TOKEN', '{{ csrf_token() }}');
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.upload.addEventListener('progress', function(ev) {
        if (ev.lengthComputable) {
            var pct = Math.round((ev.loaded / ev.total) * 100);
            document.getElementById('progressBar').style.width = pct + '%';
        }
    });

    xhr.onload = function() {
        document.getElementById('uploadProgress').style.display = 'none';

        if (xhr.status === 200) {
            var data = JSON.parse(xhr.responseText);
            uploadModal.hide();
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Factura subida', text: data.message || 'Archivo guardado correctamente.', timer: 2000, showConfirmButton: false });
            }
            setTimeout(function() { location.reload(); }, 2100);
        } else {
            var errData = {};
            try { errData = JSON.parse(xhr.responseText); } catch(ex) {}
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Error', text: errData.message || 'No se pudo subir el archivo.' });
            } else {
                alert(errData.message || 'Error al subir archivo.');
            }
        }
    };

    xhr.onerror = function() {
        document.getElementById('uploadProgress').style.display = 'none';
        alert('Error de conexion al subir el archivo.');
    };

    xhr.send(formData);
});
</script>
@endsection
