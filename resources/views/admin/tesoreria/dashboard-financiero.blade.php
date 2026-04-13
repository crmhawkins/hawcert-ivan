@extends('layouts.appAdmin')

@section('title', 'Dashboard Financiero')

@section('content')
<style>
    .df-card { padding: 12px 16px; margin-bottom: 8px; }
    .df-card h6 { font-size: 11px; color: #6b7280; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.5px; }
    .df-card .val { font-size: 20px; font-weight: 700; margin: 0; }
    .df-table { font-size: 12px; }
    .df-table th, .df-table td { padding: 6px 8px !important; vertical-align: middle; }
    .df-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; }
    .df-section-title { font-size: 13px; font-weight: 700; margin-bottom: 6px; }
    .df-badge { font-size: 10px; padding: 2px 6px; }
    .df-btn-sm { font-size: 11px; padding: 2px 8px; }
    .df-filter { font-size: 12px; }
    .df-filter .form-control, .df-filter .form-select { font-size: 12px; padding: 4px 8px; height: 30px; }
    .df-filter .btn { font-size: 12px; padding: 4px 12px; height: 30px; }
    .df-pagination .page-link { font-size: 11px; padding: 2px 8px; }
</style>

<div class="container-fluid py-2">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fw-bold mb-0" style="font-size: 16px;"><i class="fas fa-chart-line me-1" style="color: #0891b2;"></i>Dashboard Financiero</h5>
        <div>
            <button class="btn btn-outline-warning df-btn-sm" onclick="asignarReferencias()" title="Asignar refs a facturas 2026 sin numero"><i class="fas fa-hashtag"></i> Asignar Refs</button>
            <a href="{{ route('admin.diarioCaja.index') }}" class="btn btn-outline-secondary df-btn-sm">Diario</a>
            <a href="{{ route('admin.facturas.index') }}" class="btn btn-outline-secondary df-btn-sm">Facturas</a>
        </div>
    </div>

    {{-- Filtros --}}
    <div class="card shadow-sm border-0 mb-2">
        <div class="card-body py-2 px-3 df-filter">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col"><input type="date" name="fecha_desde" class="form-control" value="{{ $fechaDesde }}"></div>
                <div class="col"><input type="date" name="fecha_hasta" class="form-control" value="{{ $fechaHasta }}"></div>
                <div class="col">
                    <select name="estado" class="form-select">
                        <option value="todos" {{ $estado === 'todos' ? 'selected' : '' }}>Todos</option>
                        <option value="pendiente" {{ $estado === 'pendiente' ? 'selected' : '' }}>Pendientes</option>
                        <option value="cobrada" {{ $estado === 'cobrada' ? 'selected' : '' }}>Cobradas</option>
                        <option value="cancelada" {{ $estado === 'cancelada' ? 'selected' : '' }}>Canceladas</option>
                    </select>
                </div>
                <div class="col-auto"><button type="submit" class="btn df-btn-sm" style="background:#0891b2;color:#fff;"><i class="fas fa-filter"></i> Filtrar</button></div>
            </form>
        </div>
    </div>

    {{-- Tarjetas resumen --}}
    <div class="row g-2 mb-2">
        <div class="col-3">
            <div class="card shadow-sm border-0 df-card" style="border-left:3px solid #0891b2!important;">
                <h6>Facturado</h6>
                <p class="val">{{ number_format($totalFacturado, 0, ',', '.') }} &euro;</p>
                <small class="text-muted" style="font-size:10px;">{{ $numFacturas }} fact.</small>
            </div>
        </div>
        <div class="col-3">
            <div class="card shadow-sm border-0 df-card" style="border-left:3px solid #10b981!important;">
                <h6>Cobrado</h6>
                <p class="val text-success">{{ number_format($totalCobrado, 0, ',', '.') }} &euro;</p>
            </div>
        </div>
        <div class="col-3">
            <div class="card shadow-sm border-0 df-card" style="border-left:3px solid #f59e0b!important;">
                <h6>Pendiente</h6>
                <p class="val text-warning">{{ number_format($totalPendiente, 0, ',', '.') }} &euro;</p>
            </div>
        </div>
        <div class="col-3">
            <div class="card shadow-sm border-0 df-card" style="border-left:3px solid #ef4444!important;">
                <h6>Cancelado</h6>
                <p class="val text-danger">{{ number_format($totalCancelado, 0, ',', '.') }} &euro;</p>
            </div>
        </div>
    </div>

    {{-- Graficos --}}
    <div class="row g-2 mb-2">
        <div class="col-6">
            <div class="card shadow-sm border-0">
                <div class="card-body py-1 px-3">
                    <p class="df-section-title mb-0" style="font-size:11px;"><i class="fas fa-chart-bar me-1" style="color:#0891b2;"></i>Ingresos (6 meses)</p>
                    <canvas id="chartIngresosMes" height="30"></canvas>
                </div>
            </div>
        </div>
        <div class="col-3">
            <div class="card shadow-sm border-0">
                <div class="card-body py-1 px-2" style="max-width:200px;">
                    <p class="df-section-title mb-0" style="font-size:10px;"><i class="fas fa-chart-pie me-1" style="color:#0891b2;"></i>Por Canal</p>
                    <canvas id="chartCanales" height="50"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- Facturas pendientes 2026 - PAGINADAS 15/pagina --}}
    @if($facturasAntiguas->total() > 0)
    <div class="card shadow-sm border-0 mb-2" style="border-left:3px solid #f59e0b!important;">
        <div class="card-body py-2 px-3">
            <p class="df-section-title text-warning mb-1"><i class="fas fa-exclamation-triangle me-1"></i>Facturas Pendientes 2026 &mdash; {{ $facturasAntiguas->total() }}</p>
            <table class="table table-hover mb-0 df-table">
                <thead><tr><th>Ref.</th><th>Cliente</th><th>Fecha</th><th>Total</th><th>F.Cobro</th><th>Dias</th><th>Accion</th></tr></thead>
                <tbody>
                @foreach($facturasAntiguas as $f)
                    <tr>
                        <td class="fw-semibold">{{ $f->reference ?: '-' }}</td>
                        <td>{{ optional($f->cliente)->nombre ?? '' }} {{ optional($f->cliente)->apellido1 ?? '' }}</td>
                        <td>{{ $f->fecha ? \Carbon\Carbon::parse($f->fecha)->format('d/m') : '-' }}</td>
                        <td class="fw-bold">{{ number_format($f->total, 2, ',', '.') }}&euro;</td>
                        <td>{{ $f->fecha_cobro ? \Carbon\Carbon::parse($f->fecha_cobro)->format('d/m/Y') : '-' }}</td>
                        <td><span class="badge bg-warning text-dark df-badge">{{ $f->fecha ? now()->diffInDays(\Carbon\Carbon::parse($f->fecha)) : '?' }}d</span></td>
                        <td>
                            <a href="{{ route('metalicos.create', ['factura_id' => $f->id, 'concepto' => 'Cobro factura ' . $f->reference, 'cantidad' => $f->total]) }}" class="btn btn-success df-btn-sm"><i class="fas fa-coins"></i></a>
                            <button class="btn btn-outline-success df-btn-sm" onclick="cambiarEstado({{ $f->id }}, 'cobrada')"><i class="fas fa-check"></i></button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($facturasAntiguas->hasPages())
        <div class="card-footer bg-white py-1 df-pagination">
            {{ $facturasAntiguas->appends(request()->query())->links() }}
        </div>
        @endif
    </div>
    @endif

    {{-- Tabla facturas del periodo --}}
    <div class="card shadow-sm border-0">
        <div class="card-body py-2 px-3">
            <p class="df-section-title mb-1"><i class="fas fa-list me-1" style="color:#0891b2;"></i>Facturas del Periodo</p>
            @php
                $toggleDir = $direction === 'asc' ? 'desc' : 'asc';
                $qParams = request()->except(['order_by', 'direction', 'page']);
            @endphp
            <div style="max-height: 400px; overflow-y: auto;">
                <table class="table table-hover mb-0 df-table">
                    <thead style="position:sticky;top:0;background:#f8fafc;z-index:1;">
                        <tr>
                            <th><a href="{{ route('admin.tesoreria.dashboard', array_merge($qParams, ['order_by'=>'reference','direction'=>$orderBy==='reference'?$toggleDir:'desc'])) }}" class="text-dark text-decoration-none">Ref. {!! $orderBy==='reference' ? ($direction==='asc'?'&#9650;':'&#9660;') : '' !!}</a></th>
                            <th>Cliente</th>
                            <th>Reserva</th>
                            <th>Entrada</th>
                            <th>Salida</th>
                            <th><a href="{{ route('admin.tesoreria.dashboard', array_merge($qParams, ['order_by'=>'fecha','direction'=>$orderBy==='fecha'?$toggleDir:'desc'])) }}" class="text-dark text-decoration-none">F.Factura {!! $orderBy==='fecha' ? ($direction==='asc'?'&#9650;':'&#9660;') : '' !!}</a></th>
                            <th><a href="{{ route('admin.tesoreria.dashboard', array_merge($qParams, ['order_by'=>'total','direction'=>$orderBy==='total'?$toggleDir:'desc'])) }}" class="text-dark text-decoration-none">Total {!! $orderBy==='total' ? ($direction==='asc'?'&#9650;':'&#9660;') : '' !!}</a></th>
                            <th>F.Cobro</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($facturas as $f)
                        <tr>
                            <td class="fw-semibold">{{ $f->reference ?: '-' }}</td>
                            <td>{{ optional($f->cliente)->nombre ?? '' }} {{ optional($f->cliente)->apellido1 ?? '' }}</td>
                            <td><small class="text-muted">{{ optional($f->reserva)->codigo_reserva ?? '-' }}</small></td>
                            <td>{{ optional($f->reserva)->fecha_entrada ? \Carbon\Carbon::parse($f->reserva->fecha_entrada)->format('d/m') : '-' }}</td>
                            <td>{{ optional($f->reserva)->fecha_salida ? \Carbon\Carbon::parse($f->reserva->fecha_salida)->format('d/m') : '-' }}</td>
                            <td>{{ $f->fecha ? \Carbon\Carbon::parse($f->fecha)->format('d/m/Y') : '-' }}</td>
                            <td class="fw-bold">{{ number_format($f->total, 2, ',', '.') }}&euro;</td>
                            <td>{{ $f->fecha_cobro ? \Carbon\Carbon::parse($f->fecha_cobro)->format('d/m/Y') : '-' }}</td>
                            <td>
                                <select class="form-select form-select-sm estado-select" data-factura-id="{{ $f->id }}"
                                    style="width:110px;font-size:11px;font-weight:700;padding:2px 4px;height:26px;border-radius:4px;
                                    {{ in_array($f->invoice_status_id, [1,2]) ? 'background:#dc2626;color:#fff;border-color:#dc2626;' : '' }}
                                    {{ in_array($f->invoice_status_id, [3,4,6]) ? 'background:#16a34a;color:#fff;border-color:#16a34a;' : '' }}
                                    {{ in_array($f->invoice_status_id, [5,7]) ? 'background:#6b7280;color:#fff;border-color:#6b7280;' : '' }}">
                                    <option value="pendiente" {{ in_array($f->invoice_status_id, [1,2]) ? 'selected' : '' }}>Pendiente</option>
                                    <option value="cobrada" {{ in_array($f->invoice_status_id, [3,4,6]) ? 'selected' : '' }}>Cobrada</option>
                                    <option value="cancelada" {{ in_array($f->invoice_status_id, [5,7]) ? 'selected' : '' }}>Cancelada</option>
                                </select>
                            </td>
                            <td><a href="{{ route('admin.facturas.generatePdf', $f->id) }}" class="text-muted" title="PDF"><i class="fas fa-file-pdf"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center py-2 text-muted">Sin facturas</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($facturas->hasPages())
        <div class="card-footer bg-white py-1 df-pagination">
            {{ $facturas->appends(request()->query())->links() }}
        </div>
        @endif
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    new Chart(document.getElementById('chartIngresosMes').getContext('2d'), {
        type: 'bar',
        data: {
            labels: {!! json_encode($ingresosPorMes->pluck('mes')) !!},
            datasets: [{ data: {!! json_encode($ingresosPorMes->pluck('total')) !!}, backgroundColor: 'rgba(8,145,178,0.6)', borderColor: '#0891b2', borderWidth: 1, borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { font: { size: 9 }, callback: function(v) { return (v/1000).toFixed(0)+'k'; } } }, x: { ticks: { font: { size: 9 } } } } }
    });
    new Chart(document.getElementById('chartCanales').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: {!! json_encode($ingresosPorCanal->pluck('canal')) !!},
            datasets: [{ data: {!! json_encode($ingresosPorCanal->pluck('total')) !!}, backgroundColor: ['#0891b2','#f59e0b','#10b981','#ef4444','#8b5cf6'], borderWidth: 1, borderColor: '#fff' }]
        },
        options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 8 }, boxWidth: 8, padding: 4 } } } }
    });
    document.querySelectorAll('.estado-select').forEach(function(s) {
        s.addEventListener('change', function() { cambiarEstado(this.dataset.facturaId, this.value); });
    });
    function cambiarEstado(id, estado) {
        fetch('/admin/tesoreria/factura/' + id + '/estado', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify({ estado: estado })
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: d.message, timer: 1200, showConfirmButton: false });
                if (estado === 'cobrada') setTimeout(function(){ location.reload(); }, 1300);
            }
        });
    }
    function asignarReferencias() {
        if (!confirm('Asignar referencias consecutivas a facturas de 2026 sin numero?')) return;
        fetch('/admin/tesoreria/facturas/asignar-referencias', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                if (typeof Swal !== 'undefined') Swal.fire({ icon: 'success', title: d.message, text: 'Desde ' + (d.primera_nueva||'') + ' hasta ' + (d.ultima_nueva||''), timer: 3000 });
                setTimeout(function(){ location.reload(); }, 3200);
            }
        });
    }
</script>
@endsection
