<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_ca_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('public_key');
            $table->text('private_key'); // cifrada con Crypt (APP_KEY)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_ca_keys');
    }
};
