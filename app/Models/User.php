<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 *           email_verification_sent_at, auth0_sub, onboarding.
 *
 * Casts: email_verified_at → datetime, email_verification_sent_at → datetime,
 *        email_verified → boolean, password → hashed, onboarding → array.
 *
 * Hidden (serialization): password, remember_token.
 *
 * @property int $id
 * @property string $name Display name.
 * @property string $email Unique e-mail address (login identifier).
 * @property string|null $password Bcrypt hash (auto-cast via 'hashed'); null for Auth0-only users who have no password.
 * @property string|null $remember_token Laravel session remember token.
 * @property string|null $auth0_sub Auth0 subject identifier (e.g. "google-oauth2|123"); null for legacy accounts.
 * @property bool $email_verified Whether the user has confirmed their e-mail address.
 * @property \Illuminate\Support\Carbon|null $email_verified_at Timestamp of successful verification; null until verified.
 * @property \Illuminate\Support\Carbon|null $email_verification_sent_at Timestamp of the most recent verification e-mail dispatch; used for resend rate-limiting (2-minute window).
 * @property array<string, string>|null $onboarding Dismissed onboarding flags, keyed by flag name (see self::ONBOARDING_KEYS) and mapped to the ISO-8601 instant of dismissal; null until the user dismisses their first.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Link>                        $links
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmailVerificationToken>      $emailVerificationTokens
 * @property-read \App\Models\UserSubdomain|null $subdomain
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
        'onboarding',
    ];

    /**
     * Onboarding flags the API accepts. A key absent from this list is rejected
     * with 422, so a compromised or stale client cannot bloat the JSON column
     * with arbitrary keys.
     *
     * @var list<string>
     */
    public const ONBOARDING_KEYS = [
        'links.tour',
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
        // Internal at-most-once guard for SendWelcomeEmailJob (see that class's docblock).
        // No security impact (it's the user's own data), but it's not API surface anyone
        // asked for — keep it out of `register`/`me`/etc. responses.
        'welcome_email_sent_at',
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
            'welcome_email_sent_at' => 'datetime',
            'email_verified' => 'boolean',
            'password' => 'hashed',
            'onboarding' => 'array',
        ];
    }

    /**
     * Whether the user already dismissed the given onboarding flag.
     *
     * @param  string  $key  One of self::ONBOARDING_KEYS (e.g. 'links.tour').
     */
    public function hasSeenOnboarding(string $key): bool
    {
        return isset($this->onboarding[$key]);
    }

    /**
     * Records that the user dismissed the given onboarding flag, stamping the
     * moment it happened. Idempotent: re-marking an already-seen flag keeps the
     * original timestamp, so the first-seen date stays meaningful.
     *
     * @param  string  $key  One of self::ONBOARDING_KEYS (e.g. 'links.tour').
     */
    public function markOnboardingSeen(string $key): void
    {
        if ($this->hasSeenOnboarding($key)) {
            return;
        }

        // Reassign the whole array: mutating $this->onboarding[$key] in place
        // does not mark the attribute dirty on an 'array'-cast column.
        $this->onboarding = array_merge($this->onboarding ?? [], [
            $key => now()->toIso8601String(),
        ]);

        $this->save();
    }

    /**
     * All shortened links owned by this user (hasMany Link).
     */
    public function links()
    {
        return $this->hasMany(Link::class);
    }

    /**
     * The subdomain claimed by this user, if any (hasOne UserSubdomain).
     */
    public function subdomain(): HasOne
    {
        return $this->hasOne(\App\Models\UserSubdomain::class);
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
        if (filled($this->auth0_sub)) {
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
