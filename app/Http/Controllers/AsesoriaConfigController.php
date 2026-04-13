<?php

namespace App\Http\Controllers;

use App\Models\Asesoria;
use App\Services\InformeTrimestralService;
use App\Services\AlertaEquipoService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AsesoriaConfigController extends Controller
{
    public function index()
    {
        $asesorias = Asesoria::all();

        return view('admin.configuracion.asesorias.index', compact('asesorias'));
    }

    public function create()
    {
        return view('admin.configuracion.asesorias.form');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'telefono' => 'nullable|string|max:50',
            'frecuencia' => 'required|in:trimestral,mensual',
            'dia_envio' => 'nullable|integer|min:1|max:31',
            'hora_envio' => 'nullable|string',
            'enviar_diario_caja' => 'nullable|boolean',
            'enviar_facturas_emitidas' => 'nullable|boolean',
            'enviar_facturas_recibidas' => 'nullable|boolean',
            'enviar_zip_pdfs' => 'nullable|boolean',
            'formato_preferido' => 'nullable|in:excel,pdf',
            'activo' => 'nullable|boolean',
        ]);

        $validated['enviar_diario_caja'] = $request->boolean('enviar_diario_caja');
        $validated['enviar_facturas_emitidas'] = $request->boolean('enviar_facturas_emitidas');
        $validated['enviar_facturas_recibidas'] = $request->boolean('enviar_facturas_recibidas');
        $validated['enviar_zip_pdfs'] = $request->boolean('enviar_zip_pdfs');
        $validated['activo'] = $request->boolean('activo');

        Asesoria::create($validated);

        return redirect()->route('asesorias.index')
            ->with('success', 'Asesoria creada correctamente.');
    }

    public function edit($id)
    {
        $asesoria = Asesoria::findOrFail($id);

        return view('admin.configuracion.asesorias.form', compact('asesoria'));
    }

    public function update(Request $request, $id)
    {
        $asesoria = Asesoria::findOrFail($id);

        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'telefono' => 'nullable|string|max:50',
            'frecuencia' => 'required|in:trimestral,mensual',
            'dia_envio' => 'nullable|integer|min:1|max:31',
            'hora_envio' => 'nullable|string',
            'enviar_diario_caja' => 'nullable|boolean',
            'enviar_facturas_emitidas' => 'nullable|boolean',
            'enviar_facturas_recibidas' => 'nullable|boolean',
            'enviar_zip_pdfs' => 'nullable|boolean',
            'formato_preferido' => 'nullable|in:excel,pdf',
            'activo' => 'nullable|boolean',
        ]);

        $validated['enviar_diario_caja'] = $request->boolean('enviar_diario_caja');
        $validated['enviar_facturas_emitidas'] = $request->boolean('enviar_facturas_emitidas');
        $validated['enviar_facturas_recibidas'] = $request->boolean('enviar_facturas_recibidas');
        $validated['enviar_zip_pdfs'] = $request->boolean('enviar_zip_pdfs');
        $validated['activo'] = $request->boolean('activo');

        $asesoria->update($validated);

        return redirect()->route('asesorias.index')
            ->with('success', 'Asesoria actualizada correctamente.');
    }

    public function destroy($id)
    {
        $asesoria = Asesoria::findOrFail($id);
        $asesoria->delete();

        return redirect()->route('asesorias.index')
            ->with('success', 'Asesoria eliminada correctamente.');
    }

    /**
     * Enviar informe ahora para la asesoria indicada (trimestre actual).
     */
    public function enviarAhora($id)
    {
        $asesoria = Asesoria::findOrFail($id);

        $ahora = Carbon::now();
        $trimestre = (int) ceil($ahora->month / 3);
        $anio = $ahora->year;

        try {
            app(InformeTrimestralService::class)->generarYEnviar($asesoria, $trimestre, $anio);

            return response()->json([
                'success' => true,
                'message' => "Informe T{$trimestre}/{$anio} enviado a {$asesoria->email}.",
            ]);
        } catch (\Throwable $e) {
            // Notificar al equipo via WhatsApp
            try {
                app(AlertaEquipoService::class)->enviarWhatsApp(
                    "Error al enviar informe a {$asesoria->nombre} ({$asesoria->email}): {$e->getMessage()}"
                );
            } catch (\Throwable $alertaEx) {
                report($alertaEx);
            }

            return response()->json([
                'success' => false,
                'message' => "Error al enviar informe: {$e->getMessage()}",
            ], 500);
        }
    }
}
