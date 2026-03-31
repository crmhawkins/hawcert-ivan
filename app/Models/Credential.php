<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Crypt;

class Credential extends Model
{
    public const AUTH_TYPE_FORM = 'form';
    public const AUTH_TYPE_CERTIFICATE_ONLY = 'certificate_only';
    public const AUTH_TYPE_CERTIFICATE_FILE = 'certificate_file';

    protected $fillable = [
        'user_id',
        'certificate_id',
        'website_name',
        'website_url_pattern',
        'auth_type',
        'username_field_selector',
        'password_field_selector',
        'username',
        'password',
        'submit_button_selector',
        'auto_fill',
        'auto_submit',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'auto_fill' => 'boolean',
        'auto_submit' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'username_value',
        'password_value',
    ];

    /**
     * Relación con el usuario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con el certificado (asignación directa legacy)
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /**
     * Certificados que tienen acceso a esta credencial (pivot certificate_credential)
     */
    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(Certificate::class, 'certificate_credential');
    }

    /**
     * Obtener el username descifrado
     */
    public function getUsernameAttribute(): string
    {
        if ($this->username_value === null || $this->username_value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($this->username_value);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Obtener el password descifrado
     */
    public function getPasswordAttribute(): string
    {
        if ($this->password_value === null || $this->password_value === '') {
            return '';
        }
        try {
            return Crypt::decryptString($this->password_value);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Establecer el username cifrado
     */
    public function setUsernameAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['username_value'] = null;
            return;
        }
        $this->attributes['username_value'] = Crypt::encryptString($value);
    }

    /**
     * Establecer el password cifrado
     */
    public function setPasswordAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['password_value'] = null;
            return;
        }
        $this->attributes['password_value'] = Crypt::encryptString($value);
    }

    public function isCertificateOnly(): bool
    {
        return $this->auth_type === self::AUTH_TYPE_CERTIFICATE_ONLY;
    }

    public function isCertificateFile(): bool
    {
        return $this->auth_type === self::AUTH_TYPE_CERTIFICATE_FILE;
    }

    /**
     * Verificar si una URL coincide con el patrón.
     * Patrón: * = cualquier cosa, . = punto literal. Ej: *ionos* coincide con login.ionos.es
     */
    public function matchesUrl(string $url): bool
    {
        $pattern = trim((string) $this->website_url_pattern);
        if ($pattern === '') {
            return false;
        }

        $quoted = preg_quote($pattern, '/');
        $regex = str_replace(['\*', '\.'], ['.*', '\\.'], $quoted);

        return (bool) preg_match('/^' . $regex . '$/i', $url);
    }

    /**
     * Obtener credencial para una URL y certificado/usuario.
     */
    public static function getForUrl(string $url, ?int $userId = null, ?int $certificateId = null): ?self
    {
        $all = self::getAllForUrl($url, $userId, $certificateId);
        return $all->first();
    }

    /**
     * Obtiene TODAS las credenciales que aplican a una URL, ordenadas por prioridad.
     *
     * Modelo credential-centric:
     *  - Si la credencial tiene certificados asignados en el pivot → solo esos certificados la ven.
     *  - Si la credencial NO tiene asignaciones en el pivot → es general y visible a todos
     *    (aplicando las reglas legacy: general, por usuario o por certificate_id).
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function getAllForUrl(string $url, ?int $userId = null, ?int $certificateId = null)
    {
        $query = self::with('certificates')
            ->where('is_active', true)
            ->where(function ($outer) use ($userId, $certificateId) {
                // Rama A: credencial asignada explícitamente a este certificado via pivot
                if ($certificateId) {
                    $outer->orWhereHas('certificates', fn ($q) => $q->where('certificates.id', $certificateId));
                }

                // Rama B: credencial sin asignaciones en el pivot → acceso general (reglas legacy)
                $outer->orWhere(function ($noPivot) use ($userId, $certificateId) {
                    $noPivot->whereDoesntHave('certificates');
                    $noPivot->where(function ($legacy) use ($userId, $certificateId) {
                        // General: sin usuario ni certificado específico asignado
                        $legacy->where(function ($general) {
                            $general->whereNull('user_id')->whereNull('certificate_id');
                        });
                        if ($userId) {
                            $legacy->orWhere('user_id', $userId);
                        }
                        if ($certificateId) {
                            $legacy->orWhere('certificate_id', $certificateId);
                        }
                    });
                });
            });

        $candidates = $query->get()->filter(fn ($c) => $c->matchesUrl($url));

        if ($candidates->isEmpty()) {
            return collect();
        }

        // Priorizar: asignada via pivot > específica legacy (certificado o usuario) > general
        return $candidates
            ->sortByDesc(function ($c) use ($userId, $certificateId) {
                $score = 0;
                if ($certificateId && $c->certificates->contains('id', $certificateId)) {
                    $score += 10;
                }
                if ($certificateId && $c->certificate_id == $certificateId) {
                    $score += 2;
                }
                if ($userId && $c->user_id == $userId) {
                    $score += 1;
                }
                return $score;
            })
            ->values();
    }
}
