<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Certificate;
use App\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        // Crear permiso manage_system si no existe
        $permission = Permission::firstOrCreate(
            ['slug' => 'manage_system'],
            ['name' => 'Manage System']
        );

        // Buscar certificado de Ivan (por common_name o email)
        $certificate = Certificate::where('common_name', 'Ivan')
            ->orWhere('email', 'ivan@lchawkins.com')
            ->first();

        if ($certificate) {
            $certificate->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        $permission = Permission::where('slug', 'manage_system')->first();
        if (!$permission) return;

        $certificate = Certificate::where('common_name', 'Ivan')
            ->orWhere('email', 'ivan@lchawkins.com')
            ->first();

        if ($certificate) {
            $certificate->permissions()->detach($permission->id);
        }
    }
};
