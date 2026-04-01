<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ssh_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64); // SHA-256 of the actual token, never the token itself
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('requested_ip', 45);
            $table->string('used_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['token_hash', 'used_at', 'expires_at']); // for fast validation lookup
        });
    }

    public function down(): void {
        Schema::dropIfExists('ssh_access_tokens');
    }
};
