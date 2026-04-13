<?php

namespace App\Console\Commands;

use App\Models\Asesoria;
use App\Services\InformeTrimestralService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnviarInformeTrimestralCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'asesoria:enviar-trimestral
                            {--force : Enviar aunque no sea ultimo dia de trimestre}';

    /**
     * The console command description.
     */
    protected $description = 'Enviar informe trimestral a asesorias configuradas';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if today is last day of quarter (unless --force)
        if (!$this->option('force') && !InformeTrimestralService::esUltimoDiaTrimestre()) {
            $this->info('Hoy no es ultimo dia de trimestre. Usa --force para enviar igualmente.');
            return self::SUCCESS;
        }

        $periodo = InformeTrimestralService::trimestreActual();
        $trimestre = $periodo['trimestre'];
        $anio = $periodo['anio'];

        $this->info("Enviando informes Q{$trimestre}/{$anio}...");

        $asesorias = Asesoria::where('activo', true)->get();

        if ($asesorias->isEmpty()) {
            $this->warn('No hay asesorias activas configuradas.');
            return self::SUCCESS;
        }

        $enviados = 0;
        $errores = 0;

        foreach ($asesorias as $asesoria) {
            $this->line("  -> {$asesoria->nombre} ({$asesoria->email})...");

            try {
                $resultado = InformeTrimestralService::generarYEnviar($asesoria, $trimestre, $anio);
                $this->info("     OK: {$resultado['message']}");
                $enviados++;
            } catch (\Exception $e) {
                $errores++;
                $mensajeError = "Error enviando informe trimestral Q{$trimestre}/{$anio} a {$asesoria->nombre} ({$asesoria->email}): " . $e->getMessage();
                $this->error("     FALLO: {$e->getMessage()}");
                Log::error("[Asesoria] Fallo envio a {$asesoria->email}: " . $e->getMessage());

                // Send WhatsApp alert
                try {
                    \App\Services\AlertaEquipoService::asesoriaFallo($mensajeError);
                } catch (\Exception $e2) {
                    Log::error("[Asesoria] Tambien fallo la alerta WhatsApp: " . $e2->getMessage());
                }
            }
        }

        $this->newLine();
        $this->info("Completado: {$enviados} enviados, {$errores} errores.");

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }
}
