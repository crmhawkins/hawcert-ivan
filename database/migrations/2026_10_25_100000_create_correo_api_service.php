<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Servicio «correo-api»: lectura (solo lectura) de todos los buzones de Hawkins Mail
 * desde el cliente ~/.hawcert/hawcert-correo. Es de tipo «api»: solo admite claves
 * firmadas con la clave privada del certificado (POST /api/signed-key/issue).
 *
 * No se asigna a ningún certificado: lo asigna un administrador en HawCert
 * (Certificados → editar → marcar «Correo API (todos los buzones)»).
 */
return new class extends Migration
{
    private const SLUG = 'correo-api';

    public function up(): void
    {
        if (DB::table('services')->where('slug', self::SLUG)->exists()) {
            return;
        }

        DB::table('services')->insert([
            'name'         => 'Correo API (todos los buzones)',
            'slug'         => self::SLUG,
            'description'  => 'Lectura de todos los buzones de Hawkins Mail desde el cliente hawcert-correo. Solo lectura; no cambia nada en la bandeja de nadie.',
            'endpoint'     => 'https://correos.hawkins.es',
            'is_active'    => true,
            'service_type' => 'api',
            'ssh_port'     => 22,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('services')->where('slug', self::SLUG)->value('id');
        if ($id) {
            DB::table('certificate_service')->where('service_id', $id)->delete();
            DB::table('services')->where('id', $id)->delete();
        }
    }
};
