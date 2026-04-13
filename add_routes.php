<?php
$file = '/var/www/html/routes/web.php';
$content = file_get_contents($file);

$marker = "->name('admin.tesoreria.asignarReferencias');";

$newRoutes = "
    // Configuracion Asesorias
    Route::get('/configuracion/asesorias', [App\Http\Controllers\AsesoriaConfigController::class, 'index'])->name('configuracion.asesorias.index');
    Route::get('/configuracion/asesorias/crear', [App\Http\Controllers\AsesoriaConfigController::class, 'create'])->name('configuracion.asesorias.create');
    Route::post('/configuracion/asesorias', [App\Http\Controllers\AsesoriaConfigController::class, 'store'])->name('configuracion.asesorias.store');
    Route::get('/configuracion/asesorias/{id}/editar', [App\Http\Controllers\AsesoriaConfigController::class, 'edit'])->name('configuracion.asesorias.edit');
    Route::put('/configuracion/asesorias/{id}', [App\Http\Controllers\AsesoriaConfigController::class, 'update'])->name('configuracion.asesorias.update');
    Route::delete('/configuracion/asesorias/{id}', [App\Http\Controllers\AsesoriaConfigController::class, 'destroy'])->name('configuracion.asesorias.destroy');
    Route::post('/configuracion/asesorias/{id}/enviar-ahora', [App\Http\Controllers\AsesoriaConfigController::class, 'enviarAhora'])->name('configuracion.asesorias.enviarAhora');

    // Facturas Recibidas
    Route::get('/facturas-recibidas', [App\Http\Controllers\FacturasRecibidasController::class, 'index'])->name('admin.facturasRecibidas.index');
    Route::post('/facturas-recibidas/{id}/subir', [App\Http\Controllers\FacturasRecibidasController::class, 'subirFactura'])->name('admin.facturasRecibidas.subir');
    Route::get('/facturas-recibidas/{id}/descargar', [App\Http\Controllers\FacturasRecibidasController::class, 'descargarFactura'])->name('admin.facturasRecibidas.descargar');";

if (strpos($content, 'facturasRecibidas') === false) {
    $pos = strpos($content, $marker);
    if ($pos !== false) {
        $insertAt = $pos + strlen($marker);
        $content = substr($content, 0, $insertAt) . $newRoutes . substr($content, $insertAt);
        file_put_contents($file, $content);
        echo "ROUTES ADDED SUCCESSFULLY\n";
    } else {
        echo "MARKER NOT FOUND\n";
    }
} else {
    echo "ROUTES ALREADY EXIST\n";
}
