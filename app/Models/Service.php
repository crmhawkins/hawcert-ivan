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

    public function isSsh(): bool
    {
        return $this->service_type === 'ssh';
    }

    public function scopeSsh($query)
    {
        return $query->where('service_type', 'ssh');
    }
}
