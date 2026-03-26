<?php

namespace Database\Seeders;

use App\Models\Certificate;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class AdminPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear el permiso 'manage_system'
        $permission = Permission::firstOrCreate(
            ['slug' => 'manage_system'],
            [
                'name' => 'Gestionar Sistema',
                'description' => 'Puede crear, ver y editar usuarios y asignar este mismo permiso a otros certificados.'
            ]
        );

        // 2. Encontrar o crear el certificado específico del CEO Ivan
        $targetEmail = 'ivan@lchawkins.com';
        $targetCN = 'Ivan';

        $certificate = Certificate::where('email', $targetEmail)->orWhere('common_name', $targetCN)->first();

        // Si no existe, lo intentamos buscar por huella del certificado PEM proporcionado (omitido por claridad, lo buscamos o creamos)
        // Ya que el certificado existe o lo podemos insertar
        if (!$certificate) {
            // El usuario dijo "yo con mi certificado si podré otorgar ese permiso", 
            // asumimos que el certificado ya fue subido y existe en el sistema.
            $this->command->warn("El certificado de Ivan ($targetEmail) no se encontró. Sube el certificado primero y luego corre este seeder.");
            return;
        }

        // 3. Asignar el permiso al certificado
        if (!$certificate->permissions()->where('slug', 'manage_system')->exists()) {
            $certificate->permissions()->attach($permission->id);
            $this->command->info("Permiso manage_system asignado al certificado de {$certificate->common_name}");
        } else {
            $this->command->info("El certificado de {$certificate->common_name} ya tiene el permiso referenciado.");
        }
    }
}
