<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\CertificateUsageLog;
use App\Models\Service;
use App\Models\SshCertificate;
use App\Services\SshCertificateAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Certificados SSH temporales de un solo uso.
 *
 * Flujo del cliente (script hawcert-ssh):
 *   1. POST /api/ssh/challenge            -> nonce (2 min)
 *   2. POST /api/ssh/issue                -> certificado OpenSSH (3 min, 1 conexión)
 *      body: certificate (x509 PEM público), server_slug, public_key (ssh-ed25519 efímera),
 *            nonce, signature (RSA-SHA256 base64 de "nonce\nserver_slug\npublic_key" con la
 *            clave privada del .pem del empleado -> prueba de posesión)
 * Flujo del servidor (AuthorizedPrincipalsCommand de sshd):
 *   3. POST /api/ssh/consume              -> allowed true/false; marca el certificado como usado
 *      body: server_slug, api_secret, serial, username, connection_id (%C de sshd: identifica la conexión)
 * Público:
 *   GET  /api/ssh/ca                      -> clave pública de la CA (TrustedUserCAKeys)
 */
class SshCertificateController extends Controller
{
    /** Vida del certificado SSH emitido (segundos). Hay que conectar nada más recibirlo. */
    public const CERT_TTL_SECONDS = 180;
    /** sshd consulta el principal 2 veces por conexión (sondeo + firma): tolerancia para el MISMO handshake (mismo %C). */
    public const REUSE_GRACE_SECONDS = 20;
    public const NONCE_TTL_SECONDS = 120;
    public const MAX_ISSUES_PER_MINUTE = 12;

    public function __construct(protected SshCertificateAuthority $ca)
    {
    }

    /** Clave pública de la CA SSH (texto plano, para /etc/ssh/hawcert/ca.pub). */
    public function ca()
    {
        return response($this->ca->caPublicKeyLine() . "\n", 200, ['Content-Type' => 'text/plain']);
    }

    /** Paso 1: nonce de un solo uso para la prueba de posesión. */
    public function challenge(Request $request)
    {
        $nonce = bin2hex(random_bytes(32));
        cache()->put('ssh_nonce:' . $nonce, ['ip' => $request->ip(), 'at' => now()->timestamp], self::NONCE_TTL_SECONDS);

        return response()->json([
            'success'    => true,
            'nonce'      => $nonce,
            'expires_in' => self::NONCE_TTL_SECONDS,
            'sign'       => 'RSA-SHA256 sobre "nonce\\nserver_slug\\npublic_key" (base64)',
        ]);
    }

    /** Paso 2: emisión del certificado SSH. */
    public function issue(Request $request)
    {
        $request->validate([
            'certificate' => 'required|string',
            'server_slug' => 'required|string|max:100',
            'public_key'  => 'required|string|max:1000',
            'nonce'       => 'required|string|size:64',
            'signature'   => 'required|string|max:4000',
        ]);

        $slug     = $request->input('server_slug');
        $clientIp = $request->ip();

        $nonceData = cache()->pull('ssh_nonce:' . $request->input('nonce'));
        if (!$nonceData) {
            return $this->fail('Nonce inválido, caducado o ya utilizado. Pide otro en /api/ssh/challenge.', 400);
        }

        $x509 = @openssl_x509_read($request->input('certificate'));
        if (!$x509) {
            return $this->fail('Certificado inválido o no se pudo parsear.', 400);
        }
        $fingerprint = openssl_x509_fingerprint($x509, 'sha256');
        $certInfo    = openssl_x509_parse($x509);

        $certificate = $this->findCertificateByFingerprint($fingerprint, $certInfo['subject']['CN'] ?? null);
        if (!$certificate) {
            Log::warning('SSH issue: certificado no encontrado', ['cn' => $certInfo['subject']['CN'] ?? null, 'ip' => $clientIp, 'slug' => $slug]);
            return $this->fail('Certificado no encontrado en el sistema.', 404);
        }
        if (!$certificate->isValid()) {
            Log::warning('SSH issue: certificado inválido/expirado', ['certificate_id' => $certificate->id, 'ip' => $clientIp]);
            return $this->fail('Certificado inválido o expirado.', 403);
        }

        $service = Service::ssh()->where('slug', $slug)->where('is_active', true)->first();
        if (!$service) {
            return $this->fail('Servidor no encontrado o inactivo.', 404);
        }
        if (!$certificate->hasService($slug)) {
            Log::warning('SSH issue: sin permiso de servidor', ['certificate_id' => $certificate->id, 'slug' => $slug, 'ip' => $clientIp]);
            CertificateUsageLog::logUsage($certificate->id, 'ssh_cert_denied', $slug, $clientIp, $request->userAgent());
            return $this->fail('El certificado no tiene permiso para el servidor «' . $slug . '».', 403);
        }
        $principal = trim((string) $service->ssh_user);
        if ($principal === '') {
            return $this->fail('El servidor no tiene usuario SSH configurado en HawCert.', 500);
        }

        // Clave efímera del cliente
        try {
            $publicKey = $this->ca->canonicalPublicKeyLine($request->input('public_key'));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 400);
        }

        // Prueba de posesión: la firma debe verificar con la clave pública del x509 del empleado
        $signature = base64_decode($request->input('signature'), true);
        $payload   = $request->input('nonce') . "\n" . $slug . "\n" . $publicKey;
        $pubKey    = openssl_pkey_get_public($x509);
        if ($signature === false || !$pubKey || openssl_verify($payload, $signature, $pubKey, OPENSSL_ALGO_SHA256) !== 1) {
            Log::warning('SSH issue: firma inválida (sin prueba de posesión)', ['certificate_id' => $certificate->id, 'ip' => $clientIp]);
            return $this->fail('Firma inválida: no se ha demostrado la posesión de la clave privada del certificado.', 403);
        }

        // Límite de emisiones por certificado
        $recent = SshCertificate::where('certificate_id', $certificate->id)
            ->where('created_at', '>', now()->subMinute())
            ->count();
        if ($recent >= self::MAX_ISSUES_PER_MINUTE) {
            return $this->fail('Demasiados certificados emitidos en el último minuto.', 429);
        }

        $validAfter  = now()->timestamp - 60; // tolerancia de reloj
        $validBefore = now()->timestamp + self::CERT_TTL_SECONDS;

        $record = null;
        $line   = null;
        for ($attempt = 0; $attempt < 3 && !$record; $attempt++) {
            $serial = (int) floor(microtime(true) * 1000) * 1000 + random_int(0, 999);
            $keyId  = 'hawcert:' . $certificate->id . ':' . $serial . ':' . Str::slug($certificate->name);
            try {
                $line = $this->ca->signUserCertificate(
                    $publicKey,
                    $serial,
                    $keyId,
                    [$principal],
                    $validAfter,
                    $validBefore,
                    ['permit-pty', 'permit-port-forwarding']
                );
                $record = SshCertificate::create([
                    'certificate_id' => $certificate->id,
                    'service_id'     => $service->id,
                    'serial'         => $serial,
                    'key_id'         => $keyId,
                    'principal'      => $principal,
                    'public_key'     => $publicKey,
                    'requested_ip'   => $clientIp,
                    'expires_at'     => now()->addSeconds(self::CERT_TTL_SECONDS),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $record = null; // serie repetida (improbable): reintentar
            }
        }
        if (!$record) {
            return $this->fail('No se pudo registrar el certificado.', 500);
        }

        CertificateUsageLog::logUsage($certificate->id, 'ssh_cert_issued', $slug, $clientIp, $request->userAgent());
        Log::info('SSH cert emitido', [
            'certificate_id' => $certificate->id,
            'certificate'    => $certificate->name,
            'service'        => $slug,
            'serial'         => $record->serial,
            'ip'             => $clientIp,
        ]);

        return response()->json([
            'success'         => true,
            'ssh_certificate' => $line,
            'serial'          => $record->serial,
            'key_id'          => $record->key_id,
            'user'            => $principal,
            'host'            => $service->ssh_host,
            'port'            => $service->ssh_port ?: 22,
            'expires_at'      => $record->expires_at->toIso8601String(),
            'valid_seconds'   => self::CERT_TTL_SECONDS,
            'single_use'      => true,
        ]);
    }

    /** Paso 3 (lo llama el servidor SSH): autoriza y consume el certificado. */
    public function consume(Request $request)
    {
        $request->validate([
            'server_slug' => 'required|string',
            'api_secret'  => 'required|string',
            'serial'        => 'required|integer|min:1',
            'username'      => 'required|string|max:100',
            'connection_id' => 'nullable|string|max:128',
        ]);

        $serverIp = $request->ip();
        $service  = Service::ssh()->where('slug', $request->server_slug)->where('is_active', true)->first();
        if (!$service) {
            return response()->json(['success' => false, 'allowed' => false, 'message' => 'Servidor no encontrado'], 404);
        }
        if (!hash_equals((string) $service->api_secret, (string) $request->api_secret)) {
            Log::warning('SSH consume: api_secret inválido', ['slug' => $request->server_slug, 'ip' => $serverIp]);
            return response()->json(['success' => false, 'allowed' => false, 'message' => 'No autorizado'], 401);
        }

        $serial       = (int) $request->serial;
        $username     = $request->username;
        $connectionId = trim((string) $request->input('connection_id', '')) ?: null;

        [$allowed, $message, $record] = DB::transaction(function () use ($service, $serial, $username, $serverIp, $connectionId) {
            $record = SshCertificate::lockForUpdate()
                ->where('service_id', $service->id)
                ->where('serial', $serial)
                ->first();

            if (!$record) {
                return [false, 'Certificado desconocido', null];
            }
            if ($record->expires_at->isPast()) {
                return [false, 'Certificado caducado', $record];
            }
            if ($record->principal !== $username) {
                return [false, 'Usuario no coincide con el principal', $record];
            }
            $certificate = $record->certificate;
            if (!$certificate || !$certificate->isValid() || !$certificate->hasService($service->slug)) {
                return [false, 'El certificado del empleado ya no es válido para este servidor', $record];
            }
            if ($record->used_at === null) {
                $record->update(['used_at' => now(), 'used_from_ip' => $serverIp, 'connection_id' => $connectionId]);
                return [true, 'Primera conexión autorizada', $record];
            }
            // sshd vuelve a preguntar dentro de la MISMA conexión (sondeo de clave + intento firmado):
            // solo se tolera si es la misma conexión (%C) y dentro de la ventana corta.
            $sameConnection = $connectionId !== null && $record->connection_id !== null
                && hash_equals($record->connection_id, $connectionId);
            if ($sameConnection && $record->used_at->gt(now()->subSeconds(self::REUSE_GRACE_SECONDS))) {
                return [true, 'Mismo handshake', $record];
            }
            return [false, 'Certificado ya utilizado (un solo uso)', $record];
        });

        if ($record) {
            CertificateUsageLog::logUsage(
                $record->certificate_id,
                $allowed ? 'ssh_cert_login' : 'ssh_cert_rejected',
                $service->slug,
                $serverIp,
                'sshd/' . $username
            );
        }
        Log::info('SSH consume', [
            'slug'    => $service->slug,
            'serial'  => $serial,
            'allowed' => $allowed,
            'message' => $message,
            'ip'      => $serverIp,
        ]);

        return response()->json([
            'success'   => true,
            'allowed'   => $allowed,
            'principal' => $record?->principal,
            'message'   => $message,
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function fail(string $message, int $status)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }

    /**
     * Localiza el certificado del empleado por huella SHA256 del x509 (el CN es solo una pista).
     */
    private function findCertificateByFingerprint(string $fingerprint, ?string $cn): ?Certificate
    {
        $matches = function (Certificate $c) use ($fingerprint): bool {
            if (!is_string($c->x509_certificate)) {
                return false;
            }
            $stored = @openssl_x509_read($c->x509_certificate);
            $fp     = $stored ? openssl_x509_fingerprint($stored, 'sha256') : false;
            return is_string($fp) && hash_equals(strtolower($fp), strtolower($fingerprint));
        };

        if ($cn) {
            $candidate = Certificate::where('common_name', $cn)->first();
            if ($candidate && $matches($candidate)) {
                return $candidate;
            }
        }
        foreach (Certificate::whereNotNull('x509_certificate')->cursor() as $c) {
            if ($matches($c)) {
                return $c;
            }
        }
        return null;
    }
}
