<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('serial')->unique();
            $table->string('key_id');
            $table->string('principal', 100);
            $table->text('public_key');
            $table->string('requested_ip', 45);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('used_from_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['service_id', 'serial']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_certificates');
    }
};
