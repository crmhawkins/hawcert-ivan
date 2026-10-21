<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint administrativo para servicios externos (CRM Hawkins/Thwork/iPoint)
 * que necesitan activar o desactivar certificados en hawcert.
 *
 * Autenticación: firma HMAC-SHA256 con SSO_SHARED_SECRET.
 *
 * Uso:
 *   POST /api/admin/toggle-certificate
 *     body: {"email": "...", "is_active": true|false}
 *     headers:
 *       X-Timestamp: <unix_ts>
 *       X-Signature: hmac_sha256(email + ":" + (is_active?"1":"0") + ":" + ts, SSO_SHARED_SECRET)
 *
 * Seguridad:
 *   - Rechaza si |now - ts| > 300s (replay).
 *   - Rechaza si la firma no coincide (hash_equals).
 *
 * Respuesta:
 *   200 { ok: true, affected: N, message: "..." }
 *   400 / 401 / 404 con { ok: false, message: "..." }
 */
class CertificateAdminController extends Controller
{
    /** Ventana anti-replay en segundos. */
    private const TIMESTAMP_WINDOW = 300;

    public function toggleByEmail(Request $request)
    {
        // ── Validación de cuerpo ──────────────────────────────────────────────
        $email    = (string) $request->input('email', '');
        $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($email === '' || $isActive === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Parámetros requeridos: email (string) y is_active (bool).',
            ], 400);
        }

        // ── Anti-replay: timestamp no más viejo que 300s ──────────────────────
        $ts = (int) $request->header('X-Timestamp', 0);
        if ($ts <= 0 || abs(time() - $ts) > self::TIMESTAMP_WINDOW) {
            return response()->json([
                'ok'      => false,
                'message' => 'X-Timestamp inválido o fuera de ventana.',
            ], 401);
        }

        // ── Verificar firma HMAC ──────────────────────────────────────────────
        $secret = (string) env('SSO_SHARED_SECRET', '');
        if ($secret === '') {
            Log::error('CertificateAdmin.toggle: SSO_SHARED_SECRET no configurado.');
            return response()->json([
                'ok'      => false,
                'message' => 'Servidor mal configurado (secret).',
            ], 500);
        }

        $payload   = $email . ':' . ($isActive ? '1' : '0') . ':' . $ts;
        $expected  = hash_hmac('sha256', $payload, $secret);
        $provided  = (string) $request->header('X-Signature', '');

        if ($provided === '' || !hash_equals($expected, $provided)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Firma X-Signature inválida.',
            ], 401);
        }

        // ── Buscar certificados (email o common_name, case-insensitive) ───────
        $emailLower = mb_strtolower($email);

        $certs = Certificate::whereRaw('LOWER(email) = ?', [$emailLower])
            ->orWhereRaw('LOWER(common_name) = ?', [$emailLower])
            ->get();

        if ($certs->isEmpty()) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se encontró ningún certificado para ese email o common_name.',
                'affected'=> 0,
            ], 404);
        }

        // ── Actualizar todos ──────────────────────────────────────────────────
        $affected = 0;
        foreach ($certs as $cert) {
            $cert->is_active = $isActive ? 1 : 0;
            $cert->save();
            $affected++;
        }

        Log::info('CertificateAdmin.toggle aplicado', [
            'email'     => $email,
            'is_active' => $isActive,
            'affected'  => $affected,
        ]);

        return response()->json([
            'ok'       => true,
            'affected' => $affected,
            'message'  => $affected . ' certificado(s) actualizado(s) a is_active=' . ($isActive ? '1' : '0'),
        ]);
    }
}
