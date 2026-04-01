<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SshAdminController extends Controller
{
    /**
     * Registra o actualiza un servidor SSH desde el script de configuración automática.
     * Protegido por la clave HAWCERT_ADMIN_API_KEY del .env.
     *
     * POST /api/admin/register-ssh-server
     * Headers: X-Admin-Key: {HAWCERT_ADMIN_API_KEY}
     * Body: { name, ssh_host, ssh_port?, ssh_user?, slug? }
     */
    public function register(Request $request)
    {
        // ── Autenticación por clave admin ─────────────────────────────────────
        $adminKey = config('app.admin_api_key');

        if (!$adminKey || !hash_equals((string) $adminKey, (string) $request->header('X-Admin-Key', ''))) {
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        // ── Validar parámetros ─────────────────────────────────────────────────
        $request->validate([
            'name'     => 'required|string|max:255',
            'ssh_host' => 'required|string|max:255',
            'ssh_port' => 'nullable|integer|min:1|max:65535',
            'ssh_user' => 'nullable|string|max:100',
            'slug'     => 'nullable|string|max:100|regex:/^[a-z0-9\-]+$/',
        ]);

        // ── Buscar si ya existe por nombre ──────────────────────────
        $service = Service::where('name', $request->name)->first();

        // ── Generar api_secret seguro ──────────────────────────────────────────
        $apiSecret = bin2hex(random_bytes(32)); // 64 chars hex

        if ($service) {
            // Actualizar el servicio existente, así si ejecutamos el script varias
            // veces para el mismo servidor, solo reemplazamos el api_secret y host.
            $service->update([
                'ssh_host'   => $request->ssh_host,
                'ssh_port'   => $request->ssh_port ?? 22,
                'ssh_user'   => $request->ssh_user ?? 'root',
                'api_secret' => $apiSecret,
                'is_active'  => true,
                'service_type'=> 'ssh', // asegurar que sea ssh
            ]);
        } else {
            // ── Generar slug si es nuevo ───────────────────────────────────────────
            $slug = $request->slug
                ? $request->slug
                : Str::slug($request->name);

            // Asegurar unicidad del slug
            $baseSlug = $slug;
            $i = 2;
            while (Service::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $i++;
            }

            // ── Crear el servicio ──────────────────────────────────────────────────
            $service = Service::create([
                'name'         => $request->name,
                'slug'         => $slug,
                'description'  => 'Servidor SSH configurado automáticamente por setup_ssh_otp.py',
                'service_type' => 'ssh',
                'ssh_host'     => $request->ssh_host,
                'ssh_port'     => $request->ssh_port ?? 22,
                'ssh_user'     => $request->ssh_user ?? 'root',
                'api_secret'   => $apiSecret,
                'is_active'    => true,
            ]);
        }

        return response()->json([
            'success'    => true,
            'service_id' => $service->id,
            'slug'       => $service->slug,
            'api_secret' => $apiSecret,
            'message'    => "Servidor '{$service->name}' registrado correctamente.",
        ], 201);
    }
}
