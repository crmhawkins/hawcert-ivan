<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('certificates')
            ->where('common_name', 'Ivan')
            ->orWhere('email', 'ivan@lchawkins.com')
            ->update(['can_access_hawcert' => true]);
    }

    public function down(): void
    {
        DB::table('certificates')
            ->where('common_name', 'Ivan')
            ->orWhere('email', 'ivan@lchawkins.com')
            ->update(['can_access_hawcert' => false]);
    }
};
