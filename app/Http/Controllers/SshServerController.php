<?php
namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Service;
use App\Models\SshAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SshServerController extends Controller
{
    /**
     * Helper: get the current certificate from session.
     * Falls back to the user's first active certificate.
     */
    private function getCurrentCertificate(): ?Certificate
    {
        $certId = session('hawcert_certificate_id');
        if ($certId) {
            $cert = Certificate::find($certId);
            if ($cert && $cert->user_id === auth()->id() && $cert->isValid()) {
                return $cert;
            }
        }
        // Fallback: first valid certificate of the user
        return Certificate::where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();
    }

    /**
     * Show the list of SSH servers accessible to the current certificate.
     */
    public function index()
    {
        $certificate = $this->getCurrentCertificate();

        if (!$certificate) {
            return view('ssh.index', ['servers' => collect(), 'certificate' => null]);
        }

        // Only SSH-type services assigned to this certificate
        $servers = $certificate->services()
            ->where('services.service_type', 'ssh')
            ->where('services.is_active', true)
            ->get();

        return view('ssh.index', compact('servers', 'certificate'));
    }

    /**
     * Generate a one-time password for the given SSH service.
     * POST /servidores/{service}/token
     */
    public function requestToken(Request $request, Service $service)
    {
        $certificate = $this->getCurrentCertificate();

        // Must have a valid certificate
        if (!$certificate) {
            abort(403, 'No tienes un certificado válido activo.');
        }

        // Service must be SSH type
        if (!$service->isSsh()) {
            abort(404, 'Servicio no encontrado.');
        }

        // Certificate must have access to this service
        $hasAccess = $certificate->services()
            ->where('services.id', $service->id)
            ->exists();

        if (!$hasAccess) {
            Log::warning('SSH OTP: acceso denegado', [
                'certificate_id' => $certificate->id,
                'service_id' => $service->id,
                'ip' => $request->ip(),
            ]);
            abort(403, 'No tienes acceso a este servidor.');
        }

        try {
            $rawToken = SshAccessToken::generateFor($certificate, $service, $request->ip());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Log::info('SSH OTP generado', [
            'certificate_id' => $certificate->id,
            'certificate_name' => $certificate->name,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'ip' => $request->ip(),
        ]);

        return view('ssh.otp', [
            'token'       => $rawToken,
            'service'     => $service,
            'certificate' => $certificate,
        ]);
    }
}
