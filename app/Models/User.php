<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Authenticated application user.
 *
 * Implements JWTSubject so tymon/jwt-auth can embed the user's primary key
 * in the `sub` claim. Observer: App\Models\Observers\UserObserver, registered
 * in AppServiceProvider::boot() via User::observe(UserObserver::class).
 *
 * Fillable: name, email, password, email_verified, email_verified_at,
 *           email_verification_sent_at, auth0_sub.
 *
 * Casts: email_verified_at → datetime, email_verification_sent_at → datetime,
 *        email_verified → boolean, password → hashed.
 *
 * Hidden (serialization): password, remember_token.
 *
 * @property int $id
 * @property string $name Display name.
 * @property string $email Unique e-mail address (login identifier).
 * @property string $password Bcrypt hash (auto-cast via 'hashed').
 * @property string|null $remember_token Laravel session remember token.
 * @property string|null $auth0_sub Auth0 subject identifier (e.g. "google-oauth2|123"); null for legacy accounts.
 * @property bool $email_verified Whether the user has confirmed their e-mail address.
 * @property \Illuminate\Support\Carbon|null $email_verified_at Timestamp of successful verification; null until verified.
 * @property \Illuminate\Support\Carbon|null $email_verification_sent_at Timestamp of the most recent verification e-mail dispatch; used for resend rate-limiting (2-minute window).
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Link>                        $links
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmailVerificationToken>      $emailVerificationTokens
 */
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
        'auth0_sub',
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

    /**
     * All shortened links owned by this user (hasMany Link).
     */
    public function links()
    {
        return $this->hasMany(Link::class);
    }

    /**
     * Returns true when the user is verified.
     *
     * Auth0-authenticated users (auth0_sub is set) are treated as verified because
     * Auth0 guarantees email verification before issuing tokens. Legacy email/password
     * users still require both the boolean flag and the timestamp.
     */
    public function hasVerifiedEmail(): bool
    {
        if ($this->auth0_sub) {
            return true;
        }

        return $this->email_verified && $this->email_verified_at !== null;
    }

    /**
     * Set email_verified = true and record the timestamp; persists immediately.
     */
    public function markEmailAsVerified(): void
    {
        $this->update([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Returns true if the user may request a new verification e-mail.
     *
     * Rate limit: resend is blocked until 2 minutes have elapsed since the last
     * dispatch recorded in email_verification_sent_at. Always returns true on
     * first send (null timestamp).
     */
    public function canResendVerificationEmail(): bool
    {
        if (! $this->email_verification_sent_at) {
            return true;
        }

        // Permitir reenvio após 2 minutos
        return $this->email_verification_sent_at->addMinutes(2)->isPast();
    }

    /**
     * Record the current timestamp in email_verification_sent_at; persists immediately.
     */
    public function markVerificationEmailSent(): void
    {
        $this->update([
            'email_verification_sent_at' => now(),
        ]);
    }

    /**
     * All verification/password-reset tokens associated with this user's e-mail
     * (hasMany EmailVerificationToken via email ↔ email FK).
     */
    public function emailVerificationTokens()
    {
        return $this->hasMany(EmailVerificationToken::class, 'email', 'email');
    }
}
