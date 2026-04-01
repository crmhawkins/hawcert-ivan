<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('services', function (Blueprint $table) {
            $table->string('service_type', 10)->default('web')->after('is_active'); // 'web' or 'ssh'
            $table->string('ssh_host', 255)->nullable()->after('service_type');
            $table->unsignedSmallInteger('ssh_port')->default(22)->after('ssh_host');
            $table->string('ssh_user', 100)->nullable()->after('ssh_port');
            $table->string('api_secret', 64)->nullable()->after('ssh_user'); // server secret for API validation
        });
    }

    public function down(): void {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['service_type', 'ssh_host', 'ssh_port', 'ssh_user', 'api_secret']);
        });
    }
};
