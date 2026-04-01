<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\SshAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SshValidationController extends Controller
{
    /**
     * Validate an SSH OTP.
     * Called by the SSH server's PAM script.
     *
     * POST /api/ssh/validate
     * Body: { server_slug, api_secret, username, token }
     */
    public function validate(Request $request)
    {
        $request->validate([
            'server_slug' => 'required|string',
            'api_secret'  => 'required|string',
            'username'    => 'required|string',
            'token'       => 'required|string',
        ]);

        $serverIp = $request->ip();

        // Find the service by slug
        $service = Service::ssh()
            ->where('slug', $request->server_slug)
            ->where('is_active', true)
            ->first();

        if (!$service) {
            Log::warning('SSH validate: servidor no encontrado', [
                'slug' => $request->server_slug,
                'ip'   => $serverIp,
            ]);
            return response()->json(['success' => false, 'message' => 'Servidor no encontrado'], 404);
        }

        // Validate api_secret — constant-time comparison to prevent timing attacks
        if (!hash_equals((string) $service->api_secret, (string) $request->api_secret)) {
            Log::warning('SSH validate: api_secret inválido', [
                'slug' => $request->server_slug,
                'ip'   => $serverIp,
            ]);
            return response()->json(['success' => false, 'message' => 'No autorizado'], 401);
        }

        // Validate and consume the token
        [$valid, $certificate] = SshAccessToken::consumeToken(
            $request->token,
            $service,
            $serverIp
        );

        if (!$valid || !$certificate) {
            Log::warning('SSH validate: token inválido o expirado', [
                'slug'     => $request->server_slug,
                'username' => $request->username,
                'ip'       => $serverIp,
            ]);
            return response()->json(['success' => false, 'message' => 'Token inválido, expirado o ya utilizado'], 403);
        }

        Log::info('SSH acceso concedido', [
            'certificate_id'   => $certificate->id,
            'certificate_name' => $certificate->name,
            'service_id'       => $service->id,
            'service_name'     => $service->name,
            'username'         => $request->username,
            'server_ip'        => $serverIp,
        ]);

        return response()->json([
            'success'          => true,
            'message'          => 'Acceso autorizado',
            'certificate_name' => $certificate->name,
            'certificate_email'=> $certificate->email,
        ]);
    }
}
