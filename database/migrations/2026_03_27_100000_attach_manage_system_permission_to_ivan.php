<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Certificate;
use App\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        // Activar can_access_hawcert en el certificado de Ivan
        Certificate::where('common_name', 'Ivan')
            ->orWhere('email', 'ivan@lchawkins.com')
            ->update(['can_access_hawcert' => true]);
    }

    public function down(): void
    {
        Certificate::where('common_name', 'Ivan')
            ->orWhere('email', 'ivan@lchawkins.com')
            ->update(['can_access_hawcert' => false]);
    }
};
