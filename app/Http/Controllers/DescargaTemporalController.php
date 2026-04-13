<?php

namespace App\Http\Controllers;

use App\Models\DescargaTemporal;
use Illuminate\Support\Facades\Storage;

class DescargaTemporalController extends Controller
{
    public function descargar($token)
    {
        $descarga = DescargaTemporal::where('token', $token)->first();

        if (!$descarga) {
            abort(404, 'El enlace de descarga no existe.');
        }

        if ($descarga->expira_en->isPast()) {
            abort(404, 'El enlace de descarga ha expirado.');
        }

        if (!Storage::exists($descarga->ruta_archivo)) {
            abort(404, 'El archivo solicitado no se encontro.');
        }

        $descarga->incrementarDescargas();

        $nombreArchivo = $descarga->descripcion
            ? $descarga->descripcion . '.' . pathinfo($descarga->ruta_archivo, PATHINFO_EXTENSION)
            : basename($descarga->ruta_archivo);

        return Storage::download($descarga->ruta_archivo, $nombreArchivo);
    }
}
