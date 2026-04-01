<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SshAccessToken extends Model
{
    protected $fillable = [
        'certificate_id', 'service_id', 'token_hash',
        'expires_at', 'used_at', 'requested_ip', 'used_ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** Tokens not yet used and not expired */
    public function scopeValid($query)
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    // ── Business logic ────────────────────────────────────────────────────────

    /**
     * Generate a new OTP for a certificate+service pair.
     * Enforces max 3 active tokens per certificate+service (rate limiting).
     * Returns the raw token (shown once to user) — only the hash is stored.
     */
    public static function generateFor(Certificate $certificate, Service $service, string $requestedIp): string
    {
        // Clean up expired tokens for this pair
        static::where('certificate_id', $certificate->id)
            ->where('service_id', $service->id)
            ->where('expires_at', '<=', now())
            ->whereNull('used_at')
            ->delete();

        // Rate limiting: max 3 active tokens per certificate+service
        $activeCount = static::where('certificate_id', $certificate->id)
            ->where('service_id', $service->id)
            ->valid()
            ->count();

        if ($activeCount >= 3) {
            throw new \RuntimeException('Demasiadas claves activas. Espera a que caduquen o úsalas antes de generar una nueva.');
        }

        // Generate cryptographically secure token (16 bytes = 32 hex chars, easy to type)
        $rawToken = strtoupper(bin2hex(random_bytes(8))); // e.g. "A3F2B1C4D5E6F7A8"
        // Format with dashes for readability: XXXX-XXXX-XXXX-XXXX
        $formatted = implode('-', str_split($rawToken, 4));

        static::create([
            'certificate_id' => $certificate->id,
            'service_id'     => $service->id,
            'token_hash'     => hash('sha256', $rawToken), // store hash, never raw
            'expires_at'     => now()->addMinutes(10),
            'requested_ip'   => $requestedIp,
        ]);

        return $formatted; // Return formatted raw token shown once to user
    }

    /**
     * Validate and consume a token atomically (prevents race conditions / double use).
     * Accepts token with or without dashes.
     * Returns [bool $valid, Certificate|null $certificate]
     */
    public static function consumeToken(string $rawToken, Service $service, string $usedIp): array
    {
        // Normalize: remove dashes and uppercase
        $normalized = strtoupper(str_replace('-', '', $rawToken));
        $tokenHash  = hash('sha256', $normalized);

        $result = DB::transaction(function () use ($tokenHash, $service, $usedIp) {
            $token = static::lockForUpdate()
                ->where('service_id', $service->id)
                ->where('token_hash', $tokenHash)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first();

            if (!$token) {
                return [false, null];
            }

            // Verify the certificate is still valid
            $certificate = $token->certificate;
            if (!$certificate || !$certificate->isValid()) {
                return [false, null];
            }

            // Verify certificate still has access to this service
            $hasAccess = $certificate->services()
                ->where('services.id', $service->id)
                ->exists();
            if (!$hasAccess) {
                return [false, null];
            }

            // Consume the token
            $token->update(['used_at' => now(), 'used_ip' => $usedIp]);

            return [true, $certificate];
        });

        return $result;
    }
}
