<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('token', 64)->unique();
            $table->string('type')->default('email_verification'); // email_verification, password_reset
            $table->timestamp('expires_at');
            $table->boolean('used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            // Índices para performance
            $table->index(['email', 'type']);
            $table->index(['token', 'type']);
            $table->index(['expires_at', 'used']);
        });

        // Atualizar tabela users para adicionar campos de verificação
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_verified')->default(false)->after('email_verified_at');
            $table->timestamp('email_verification_sent_at')->nullable()->after('email_verified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_verified', 'email_verification_sent_at']);
        });
    }
};
