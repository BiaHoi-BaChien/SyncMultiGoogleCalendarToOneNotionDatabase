<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('passkey_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('user_handle');
            $table->string('credential_id');
            $table->text('public_key_pem');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->timestamps();
            $table->unique(['user_handle', 'credential_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkey_credentials');
    }
};
