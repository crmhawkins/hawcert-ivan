<?php

namespace App\Services;

use App\Models\Asesoria;
use App\Models\DescargaTemporal;
use App\Models\Invoices;
use App\Models\Gastos;
use App\Mail\InformeTrimestralAsesoria;
use App\Exports\DiarioCajaExport;
use App\Exports\FacturasEmitidasExport;
use App\Exports\FacturasRecibidasExport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use ZipArchive;
use Carbon\Carbon;

class InformeTrimestralService
{
    /**
     * Generate and send quarterly report to asesoria.
     * Returns array with success status and message.
     */
    public static function generarYEnviar(Asesoria $asesoria, int $trimestre, int $anio): array
    {
        // Calculate date range for the quarter
        $fechaDesde = Carbon::create($anio, ($trimestre - 1) * 3 + 1, 1)->startOfDay();
        $fechaHasta = Carbon::create($anio, $trimestre * 3, 1)->endOfMonth()->endOfDay();

        $archivosAdjuntos = [];
        $rutaBase = "asesorias/Q{$trimestre}_{$anio}_" . time();

        // Ensure directory exists
        Storage::makeDirectory($rutaBase);

        // 1. Generate Excel - Diario de Caja
        if ($asesoria->enviar_diario_caja) {
            $nombreDiario = "Diario_Caja_Q{$trimestre}_{$anio}.xlsx";
            Excel::store(new DiarioCajaExport($fechaDesde->format('Y-m-d'), $fechaHasta->format('Y-m-d')), "{$rutaBase}/{$nombreDiario}");
            $archivosAdjuntos[] = ['path' => storage_path("app/{$rutaBase}/{$nombreDiario}"), 'name' => $nombreDiario];
        }

        // 2. Generate Excel - Facturas Emitidas
        if ($asesoria->enviar_facturas_emitidas) {
            $nombreEmitidas = "Facturas_Emitidas_Q{$trimestre}_{$anio}.xlsx";
            Excel::store(new FacturasEmitidasExport($fechaDesde->format('Y-m-d'), $fechaHasta->format('Y-m-d')), "{$rutaBase}/{$nombreEmitidas}");
            $archivosAdjuntos[] = ['path' => storage_path("app/{$rutaBase}/{$nombreEmitidas}"), 'name' => $nombreEmitidas];
        }

        // 3. Generate Excel - Facturas Recibidas (Gastos)
        if ($asesoria->enviar_facturas_recibidas) {
            $nombreRecibidas = "Facturas_Recibidas_Q{$trimestre}_{$anio}.xlsx";
            Excel::store(new FacturasRecibidasExport($fechaDesde->format('Y-m-d'), $fechaHasta->format('Y-m-d')), "{$rutaBase}/{$nombreRecibidas}");
            $archivosAdjuntos[] = ['path' => storage_path("app/{$rutaBase}/{$nombreRecibidas}"), 'name' => $nombreRecibidas];
        }

        // 4. Generate ZIP with invoice PDFs
        $enlaceZip = null;
        if ($asesoria->enviar_zip_pdfs) {
            $zipResult = self::generarZipFacturas($fechaDesde->format('Y-m-d'), $fechaHasta->format('Y-m-d'), $rutaBase, $trimestre, $anio);
            $enlaceZip = $zipResult;
        }

        // 5. Send email
        Mail::to($asesoria->email)->send(new InformeTrimestralAsesoria(
            $asesoria, $trimestre, $anio, $archivosAdjuntos, $enlaceZip
        ));

        Log::info("[Asesoria] Informe Q{$trimestre}/{$anio} enviado a {$asesoria->email}");

        return ['success' => true, 'message' => "Informe Q{$trimestre}/{$anio} enviado a {$asesoria->email}"];
    }

    /**
     * Generate ZIP with all invoice PDFs for the period.
     * Creates a temporary download link valid for 30 days.
     */
    private static function generarZipFacturas(string $desde, string $hasta, string $rutaBase, int $trimestre, int $anio): ?string
    {
        $facturas = Invoices::whereBetween('fecha', [$desde, $hasta])->get();

        if ($facturas->isEmpty()) return null;

        $zipName = "Facturas_PDF_Q{$trimestre}_{$anio}.zip";
        $zipPath = storage_path("app/{$rutaBase}/{$zipName}");

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            Log::error("[Asesoria] No se pudo crear ZIP: {$zipPath}");
            return null;
        }

        // For each invoice, generate PDF and add to ZIP
        // This assumes there's a route/method to generate PDF content
        // We'll use the existing generatePdf logic
        foreach ($facturas as $factura) {
            try {
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoices.pdf', ['invoice' => $factura]);
                $pdfName = ($factura->reference ?: "factura_{$factura->id}") . ".pdf";
                // Sanitize filename
                $pdfName = str_replace(['/', '\\'], '_', $pdfName);
                $zip->addFromString($pdfName, $pdf->output());
            } catch (\Exception $e) {
                Log::warning("[Asesoria] No se pudo generar PDF para factura #{$factura->id}: " . $e->getMessage());
            }
        }

        $zip->close();

        // Create temporary download token
        $token = Str::random(64);
        DescargaTemporal::create([
            'token' => $token,
            'ruta_archivo' => "{$rutaBase}/{$zipName}",
            'descripcion' => "Facturas PDF Q{$trimestre}/{$anio}",
            'expira_en' => now()->addDays(30),
        ]);

        return url("/descargas/asesoria/{$token}");
    }

    /**
     * Determine current quarter from date.
     */
    public static function trimestreActual(): array
    {
        $mes = now()->month;
        $trimestre = ceil($mes / 3);
        return ['trimestre' => $trimestre, 'anio' => now()->year];
    }

    /**
     * Check if today is the last day of a quarter.
     */
    public static function esUltimoDiaTrimestre(): bool
    {
        $hoy = now();
        return in_array($hoy->format('m-d'), ['03-31', '06-30', '09-30', '12-31']);
    }
}
