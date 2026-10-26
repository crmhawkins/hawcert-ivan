<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Clave de la autoridad certificadora SSH de HawCert (privada cifrada con APP_KEY). */
class SshCaKey extends Model
{
    protected $fillable = ['name', 'public_key', 'private_key', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $hidden = ['private_key'];
}
