<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'email_verified',
        'email_verified_at',
        'email_verification_sent_at',
    ];

    /**
     * Obtenha o identificador que será armazenado no claim "sub" do JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Retorna um array de claims customizados para serem adicionados ao JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_sent_at' => 'datetime',
            'email_verified' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function links()
    {
        return $this->hasMany(Link::class);
    }

    /**
     * Verificar se o email foi verificado
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified && $this->email_verified_at !== null;
    }

    /**
     * Marcar email como verificado
     */
    public function markEmailAsVerified(): void
    {
        $this->update([
            'email_verified' => true,
            'email_verified_at' => now()
        ]);
    }

    /**
     * Verificar se pode reenviar email de verificação (rate limiting)
     */
    public function canResendVerificationEmail(): bool
    {
        if (!$this->email_verification_sent_at) {
            return true;
        }

        // Permitir reenvio após 2 minutos
        return $this->email_verification_sent_at->addMinutes(2)->isPast();
    }

    /**
     * Marcar que email de verificação foi enviado
     */
    public function markVerificationEmailSent(): void
    {
        $this->update([
            'email_verification_sent_at' => now()
        ]);
    }

    /**
     * Relacionamento com tokens de verificação
     */
    public function emailVerificationTokens()
    {
        return $this->hasMany(EmailVerificationToken::class, 'email', 'email');
    }
}
