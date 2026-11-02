<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'endpoint', 'is_active',
        'service_type', 'ssh_host', 'ssh_port', 'ssh_user', 'api_secret',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ssh_port'  => 'integer',
    ];

    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(Certificate::class)->withPivot('auth_username');
    }

    public function sshAccessTokens(): HasMany
    {
        return $this->hasMany(SshAccessToken::class);
    }

    /**
     * Servicio de tipo API: solo se entra con una clave de un solo uso emitida en
     * /api/signed-key/issue, que exige firmar un reto con la clave privada del .pem.
     * El flujo antiguo /api/validate-access (solo certificado público) no vale para estos servicios.
     */
    public const TYPE_API = 'api';

    public function isSsh(): bool
    {
        return $this->service_type === 'ssh';
    }

    public function isApi(): bool
    {
        return $this->service_type === self::TYPE_API;
    }

    public function scopeSsh($query)
    {
        return $query->where('service_type', 'ssh');
    }

    public function scopeApi($query)
    {
        return $query->where('service_type', self::TYPE_API);
    }
}
