<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Certificado SSH temporal de un solo uso emitido por HawCert. */
class SshCertificate extends Model
{
    protected $fillable = [
        'certificate_id', 'service_id', 'serial', 'key_id', 'principal',
        'public_key', 'requested_ip', 'expires_at', 'used_at', 'used_from_ip', 'connection_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
