<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessKey;
use App\Models\Certificate;
use App\Models\CertificateUsageLog;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Claves de acceso de un solo uso para servicios de tipo «api» (p. ej. correo-api),
 * con prueba de posesión de la clave privada del .pem. Es el mismo esquema que
 * /api/ssh/challenge + /api/ssh/issue, pero en vez de un certificado SSH devuelve
 * una access key normal (ak_...) que el servicio destino consume en /api/validate-key.
 *
 * Flujo del cliente (p. ej. ~/.hawcert/hawcert-correo):
 *   1. POST /api/signed-key/challenge -> nonce (2 min, un solo uso)
 *   2. POST /api/signed-key/issue     -> access_key (2 min, un solo uso)
 *      body: certificate (x509 PEM público), service_slug, nonce,
 *            signature = base64(RSA-SHA256("hawcert-signed-key-v1\n<nonce>\n<service_slug>"))
 *            firmada con la clave privada del .pem. La clave privada NUNCA se envía.
 *   3. El cliente manda la access_key al servicio (Authorization: Bearer ak_...), y el servicio
 *      la consume en POST /api/validate-key con su propia URL (host = endpoint del servicio).
 */
class SignedKeyController extends Controller
{
    public const NONCE_TTL_SECONDS   = 120;
    public const KEY_TTL_SECONDS     = 120;
    public const MAX_KEYS_PER_MINUTE = 30;
    /** Marca en access_keys.metadata para distinguir estas claves de las del flujo sin firma. */
    public const ISSUED_VIA = 'signed-key';
    /** Prefijo del texto firmado: separa estas firmas de las del flujo SSH. */
    public const SIGNATURE_CONTEXT = 'hawcert-signed-key-v1';

    public static function signedPayload(string $nonce, string $serviceSlug): string
    {
        return self::SIGNATURE_CONTEXT . "\n" . $nonce . "\n" . $serviceSlug;
    }

    /** Paso 1: nonce de un solo uso. */
    public function challenge(Request $request)
    {
        $nonce = bin2hex(random_bytes(32));
        cache()->put('signed_key_nonce:' . $nonce, ['ip' => $request->ip(), 'at' => now()->timestamp], self::NONCE_TTL_SECONDS);

        return response()->json([
            'success'    => true,
            'nonce'      => $nonce,
            'expires_in' => self::NONCE_TTL_SECONDS,
            'sign'       => 'RSA-SHA256 sobre "' . self::SIGNATURE_CONTEXT . '\\nnonce\\nservice_slug" (base64)',
        ]);
    }

    /** Paso 2: emisión de la access key de un solo uso. */
    public function issue(Request $request)
    {
        $request->validate([
            'certificate'  => 'required|string|max:20000',
            'service_slug' => 'required|string|max:100',
            'nonce'        => 'required|string|size:64',
            'signature'    => 'required|string|max:4000',
        ]);

        $slug     = (string) $request->input('service_slug');
        $nonce    = (string) $request->input('nonce');
        $clientIp = $request->ip();

        // El nonce se consume siempre (aunque luego falle otra comprobación): no se puede reintentar con él.
        if (!cache()->pull('signed_key_nonce:' . $nonce)) {
            return $this->fail('Nonce inválido, caducado o ya utilizado. Pide otro en /api/signed-key/challenge.', 400);
        }

        $x509 = @openssl_x509_read((string) $request->input('certificate'));
        if (!$x509) {
            return $this->fail('Certificado inválido o no se pudo parsear.', 400);
        }
        $fingerprint = openssl_x509_fingerprint($x509, 'sha256');
        $certInfo    = openssl_x509_parse($x509) ?: [];

        $certificate = Certificate::findByX509Fingerprint($fingerprint, $certInfo['subject']['CN'] ?? null);
        if (!$certificate) {
            Log::warning('Signed key: certificado no encontrado', ['cn' => $certInfo['subject']['CN'] ?? null, 'ip' => $clientIp, 'slug' => $slug]);
            return $this->fail('Certificado no encontrado en el sistema.', 404);
        }
        if (!$certificate->isValid()) {
            Log::warning('Signed key: certificado inválido/expirado', ['certificate_id' => $certificate->id, 'ip' => $clientIp]);
            return $this->fail('Certificado inválido o expirado.', 403);
        }

        // Solo servicios de tipo «api»: este flujo no sirve para entrar en webs ni servidores.
        $service = Service::api()->where('slug', $slug)->where('is_active', true)->first();
        if (!$service) {
            return $this->fail('Servicio API «' . $slug . '» no encontrado o inactivo.', 404);
        }

        // Prueba de posesión antes de revelar nada sobre los permisos del certificado.
        $signature = base64_decode((string) $request->input('signature'), true);
        $pubKey    = openssl_pkey_get_public($x509);
        if ($signature === false || !$pubKey
            || openssl_verify(self::signedPayload($nonce, $slug), $signature, $pubKey, OPENSSL_ALGO_SHA256) !== 1) {
            Log::warning('Signed key: firma inválida (sin prueba de posesión)', ['certificate_id' => $certificate->id, 'ip' => $clientIp]);
            return $this->fail('Firma inválida: no se ha demostrado la posesión de la clave privada del certificado.', 403);
        }

        if (!$certificate->hasService($slug)) {
            Log::warning('Signed key: sin permiso para el servicio', ['certificate_id' => $certificate->id, 'slug' => $slug, 'ip' => $clientIp]);
            CertificateUsageLog::logUsage($certificate->id, 'signed_key_denied', $slug, $clientIp, $request->userAgent());
            return $this->fail('El certificado no tiene permiso para el servicio «' . $slug . '». Lo asigna un administrador en HawCert.', 403);
        }

        $endpoint = trim((string) $service->endpoint);
        if ($endpoint === '' || !parse_url($endpoint, PHP_URL_HOST)) {
            return $this->fail('El servicio «' . $slug . '» no tiene endpoint configurado en HawCert.', 500);
        }

        $recent = AccessKey::where('certificate_id', $certificate->id)
            ->where('service_slug', $slug)
            ->where('created_at', '>', now()->subMinute())
            ->count();
        if ($recent >= self::MAX_KEYS_PER_MINUTE) {
            return $this->fail('Demasiadas claves emitidas en el último minuto. Espera un poco.', 429);
        }

        $accessKey = AccessKey::create([
            'certificate_id' => $certificate->id,
            'key'            => 'ak_' . Str::random(48),
            'service_slug'   => $slug,
            'target_url'     => $endpoint,
            'client_ip'      => $clientIp,
            'expires_at'     => now()->addSeconds(self::KEY_TTL_SECONDS),
            'is_used'        => false,
            'metadata'       => [
                'issued_via'   => self::ISSUED_VIA,
                'generated_at' => now()->toIso8601String(),
                'user_id'      => $certificate->user_id,
            ],
        ]);

        CertificateUsageLog::logUsage($certificate->id, 'signed_key_issued', $slug, $clientIp, $request->userAgent());

        return response()->json([
            'success'       => true,
            'access_key'    => $accessKey->key,
            'expires_at'    => $accessKey->expires_at->toIso8601String(),
            'valid_seconds' => self::KEY_TTL_SECONDS,
            'single_use'    => true,
            'service'       => [
                'name'     => $service->name,
                'slug'     => $service->slug,
                'endpoint' => $endpoint,
            ],
        ]);
    }

    private function fail(string $message, int $status)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
