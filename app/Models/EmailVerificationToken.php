<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EmailVerificationToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'token',
        'type',
        'expires_at',
        'used',
        'used_at',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'used' => 'boolean'
    ];

    // Tipos de token
    const TYPE_EMAIL_VERIFICATION = 'email_verification';
    const TYPE_PASSWORD_RESET = 'password_reset';

    /**
     * Gerar um novo token
     */
    public static function generateToken(): string
    {
        return hash('sha256', Str::random(60));
    }

    /**
     * Criar token de verificação de email
     */
    public static function createEmailVerificationToken(
        string $email,
        string $ipAddress = null,
        string $userAgent = null
    ): self {
        // Invalidar tokens anteriores do mesmo tipo
        self::where('email', $email)
            ->where('type', self::TYPE_EMAIL_VERIFICATION)
            ->where('used', false)
            ->update(['used' => true, 'used_at' => now()]);

        return self::create([
            'email' => $email,
            'token' => self::generateToken(),
            'type' => self::TYPE_EMAIL_VERIFICATION,
            'expires_at' => now()->addHours(24), // 24 horas para verificação
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ]);
    }

    /**
     * Criar token de recuperação de senha
     */
    public static function createPasswordResetToken(
        string $email,
        string $ipAddress = null,
        string $userAgent = null
    ): self {
        // Invalidar tokens anteriores do mesmo tipo
        self::where('email', $email)
            ->where('type', self::TYPE_PASSWORD_RESET)
            ->where('used', false)
            ->update(['used' => true, 'used_at' => now()]);

        return self::create([
            'email' => $email,
            'token' => self::generateToken(),
            'type' => self::TYPE_PASSWORD_RESET,
            'expires_at' => now()->addHours(1), // 1 hora para reset de senha
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ]);
    }

    /**
     * Verificar se o token é válido
     */
    public function isValid(): bool
    {
        return !$this->used &&
               $this->expires_at->isFuture();
    }

    /**
     * Marcar token como usado
     */
    public function markAsUsed(): void
    {
        $this->update([
            'used' => true,
            'used_at' => now()
        ]);
    }

    /**
     * Buscar token válido
     */
    public static function findValidToken(string $token, string $type): ?self
    {
        return self::where('token', $token)
                   ->where('type', $type)
                   ->where('used', false)
                   ->where('expires_at', '>', now())
                   ->first();
    }

    /**
     * Limpar tokens expirados (para comando de limpeza)
     */
    public static function cleanExpiredTokens(): int
    {
        return self::where('expires_at', '<', now())
                   ->orWhere('used', true)
                   ->delete();
    }

    /**
     * Relacionamento com usuário (opcional, baseado no email)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }
}
