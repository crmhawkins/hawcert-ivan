<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asesoria extends Model
{
    protected $table = 'asesorias';

    protected $fillable = [
        'nombre',
        'email',
        'telefono',
        'frecuencia',
        'dia_envio',
        'hora_envio',
        'enviar_diario_caja',
        'enviar_facturas_emitidas',
        'enviar_facturas_recibidas',
        'enviar_zip_pdfs',
        'formato_preferido',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'enviar_diario_caja' => 'boolean',
            'enviar_facturas_emitidas' => 'boolean',
            'enviar_facturas_recibidas' => 'boolean',
            'enviar_zip_pdfs' => 'boolean',
            'activo' => 'boolean',
            'dia_envio' => 'integer',
            'hora_envio' => 'string',
        ];
    }
}
