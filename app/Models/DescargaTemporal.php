<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class DescargaTemporal extends Model
{
    protected $table = 'descargas_temporales';

    const UPDATED_AT = null;

    protected $fillable = [
        'token',
        'ruta_archivo',
        'descripcion',
        'expira_en',
        'descargas',
    ];

    protected function casts(): array
    {
        return [
            'expira_en' => 'datetime',
            'descargas' => 'integer',
        ];
    }

    /**
     * Scope para obtener solo descargas vigentes (no expiradas).
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('expira_en', '>', now());
    }

    /**
     * Incrementar el contador de descargas.
     */
    public function incrementarDescargas(): void
    {
        $this->increment('descargas');
    }
}
