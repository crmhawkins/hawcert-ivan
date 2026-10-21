<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ssh_certificates', function (Blueprint $table) {
            $table->string('connection_id', 128)->nullable()->after('used_from_ip'); // %C de sshd
        });
    }

    public function down(): void
    {
        Schema::table('ssh_certificates', function (Blueprint $table) {
            $table->dropColumn('connection_id');
        });
    }
};
