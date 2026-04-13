@extends('layouts.appAdmin')

@section('title', isset($asesoria) ? 'Editar Asesoria' : 'Nueva Asesoria')

@section('content')
<style>
    .df-btn-sm { font-size: 11px; padding: 2px 8px; }
    .form-label-sm { font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 2px; }
    .form-control-sm, .form-select-sm { font-size: 12px; }
    .form-check-label { font-size: 12px; }
</style>

<div class="container-fluid py-2">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="fw-bold mb-0" style="font-size: 16px;">
            <i class="fas fa-building me-1" style="color: #0891b2;"></i>
            {{ isset($asesoria) ? 'Editar Asesoria' : 'Nueva Asesoria' }}
        </h5>
        <a href="{{ route('admin.asesorias.index') }}" class="btn btn-outline-secondary df-btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Volver
        </a>
    </div>

    {{-- Validation errors --}}
    @if($errors->any())
        <div class="alert alert-danger py-2" style="font-size:12px;">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Form --}}
    <div class="card shadow-sm border-0">
        <div class="card-body py-3 px-4">
            <form method="POST" action="{{ isset($asesoria) ? route('admin.asesorias.update', $asesoria->id) : route('admin.asesorias.store') }}">
                @csrf
                @if(isset($asesoria))
                    @method('PUT')
                @endif

                <div class="row g-3">
                    {{-- Nombre --}}
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control form-control-sm" required
                               value="{{ old('nombre', $asesoria->nombre ?? '') }}"
                               placeholder="Nombre de la asesoria">
                    </div>

                    {{-- Email --}}
                    <div class="col-md-6">
                        <label class="form-label form-label-sm">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control form-control-sm" required
                               value="{{ old('email', $asesoria->email ?? '') }}"
                               placeholder="email@asesoria.com">
                    </div>

                    {{-- Telefono --}}
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Telefono</label>
                        <input type="text" name="telefono" class="form-control form-control-sm"
                               value="{{ old('telefono', $asesoria->telefono ?? '') }}"
                               placeholder="+34 600 000 000">
                    </div>

                    {{-- Frecuencia --}}
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Frecuencia</label>
                        <select name="frecuencia" class="form-select form-select-sm">
                            <option value="trimestral" {{ old('frecuencia', $asesoria->frecuencia ?? 'trimestral') === 'trimestral' ? 'selected' : '' }}>Trimestral</option>
                            <option value="mensual" {{ old('frecuencia', $asesoria->frecuencia ?? '') === 'mensual' ? 'selected' : '' }}>Mensual</option>
                        </select>
                    </div>

                    {{-- Formato preferido --}}
                    <div class="col-md-4">
                        <label class="form-label form-label-sm">Formato Preferido</label>
                        <select name="formato_preferido" class="form-select form-select-sm">
                            <option value="excel" {{ old('formato_preferido', $asesoria->formato_preferido ?? 'excel') === 'excel' ? 'selected' : '' }}>Excel</option>
                            <option value="pdf" {{ old('formato_preferido', $asesoria->formato_preferido ?? '') === 'pdf' ? 'selected' : '' }}>PDF</option>
                        </select>
                    </div>
                </div>

                {{-- Checkboxes --}}
                <div class="mt-3">
                    <label class="form-label form-label-sm d-block mb-2">Documentos a Enviar</label>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="hidden" name="enviar_diario_caja" value="0">
                                <input class="form-check-input" type="checkbox" name="enviar_diario_caja" value="1" id="chkDiario"
                                       {{ old('enviar_diario_caja', $asesoria->enviar_diario_caja ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="chkDiario">Diario de Caja</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="hidden" name="enviar_facturas_emitidas" value="0">
                                <input class="form-check-input" type="checkbox" name="enviar_facturas_emitidas" value="1" id="chkEmitidas"
                                       {{ old('enviar_facturas_emitidas', $asesoria->enviar_facturas_emitidas ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="chkEmitidas">Facturas Emitidas</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="hidden" name="enviar_facturas_recibidas" value="0">
                                <input class="form-check-input" type="checkbox" name="enviar_facturas_recibidas" value="1" id="chkRecibidas"
                                       {{ old('enviar_facturas_recibidas', $asesoria->enviar_facturas_recibidas ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="chkRecibidas">Facturas Recibidas</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input type="hidden" name="enviar_zip_pdfs" value="0">
                                <input class="form-check-input" type="checkbox" name="enviar_zip_pdfs" value="1" id="chkZip"
                                       {{ old('enviar_zip_pdfs', $asesoria->enviar_zip_pdfs ?? true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="chkZip">ZIP con PDFs</label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Activo --}}
                <div class="mt-3">
                    <div class="form-check">
                        <input type="hidden" name="activo" value="0">
                        <input class="form-check-input" type="checkbox" name="activo" value="1" id="chkActivo"
                               {{ old('activo', $asesoria->activo ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="chkActivo">Activa</label>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="mt-4">
                    <button type="submit" class="btn" style="background:#0891b2;color:#fff;font-size:13px;padding:6px 24px;">
                        <i class="fas fa-save me-1"></i>Guardar
                    </button>
                    <a href="{{ route('admin.asesorias.index') }}" class="btn btn-outline-secondary" style="font-size:13px;padding:6px 24px;">
                        Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
