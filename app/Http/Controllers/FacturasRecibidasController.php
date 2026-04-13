<?php

namespace App\Http\Controllers;

use App\Models\Gastos;
use App\Models\CategoriaGastos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FacturasRecibidasController extends Controller
{
    public function index(Request $request)
    {
        $query = Gastos::with(['categoria', 'estado']);

        if ($request->filled('fecha_desde')) {
            $query->where('date', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->where('date', '<=', $request->fecha_hasta);
        }

        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->categoria_id);
        }

        if ($request->filled('tiene_factura')) {
            if ($request->tiene_factura === 'si') {
                $query->whereNotNull('factura_foto')->where('factura_foto', '!=', '');
            } elseif ($request->tiene_factura === 'no') {
                $query->where(function ($q) {
                    $q->whereNull('factura_foto')->orWhere('factura_foto', '');
                });
            }
        }

        $gastos = $query->orderBy('date', 'desc')->paginate(25)->withQueryString();
        $categorias = CategoriaGastos::all();

        $filters = $request->only(['fecha_desde', 'fecha_hasta', 'categoria_id', 'tiene_factura']);

        return view('admin.tesoreria.facturas-recibidas', compact('gastos', 'categorias', 'filters'));
    }

    public function subirFactura(Request $request, $id)
    {
        $request->validate([
            'factura' => 'required|file|mimes:jpg,jpeg,png,gif,pdf|max:5120',
        ]);

        $gasto = Gastos::findOrFail($id);

        $archivo = $request->file('factura');
        $extension = $archivo->getClientOriginalExtension();
        $nombreArchivo = "{$id}.{$extension}";
        $ruta = $archivo->storeAs('public/facturas_recibidas', $nombreArchivo);

        $gasto->update([
            'factura_foto' => str_replace('public/', 'storage/', $ruta),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Factura subida correctamente.',
            'ruta' => $gasto->factura_foto,
        ]);
    }

    public function descargarFactura($id)
    {
        $gasto = Gastos::findOrFail($id);

        if (empty($gasto->factura_foto)) {
            abort(404, 'Este gasto no tiene factura adjunta.');
        }

        $rutaStorage = str_replace('storage/', 'public/', $gasto->factura_foto);

        if (!Storage::exists($rutaStorage)) {
            abort(404, 'El archivo de factura no se encontro.');
        }

        return Storage::download($rutaStorage, "factura_gasto_{$id}." . pathinfo($gasto->factura_foto, PATHINFO_EXTENSION));
    }
}
