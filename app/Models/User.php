<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Authenticated application user.
 *
 * Implements JWTSubject so tymon/jwt-auth can embed the user's primary key
 * in the `sub` claim. Observer: App\Models\Observers\UserObserver, registered
 * in AppServiceProvider::boot() via User::observe(UserObserver::class).
 *
 * Also uses Laravel Sanctum's HasApiTokens for the public API (/api/v1):
 * the JWT guard keeps authenticating the SPA/panel session while Sanctum
 * personal access tokens ("API keys", managed via /api/api-keys) authenticate
 * the public API. The two guards coexist and are deliberately isolated — a
 * panel JWT does not authenticate on auth:sanctum routes and vice-versa.
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
 * @property \Illuminate\Support\Carbon|null $password_changed_at Moment of the last password reset/change; null if never changed (includes Auth0-only users). Its epoch travels in the JWT `pwd_ts` claim so ApiAuthenticate can kill tokens issued before a change.
 * @property string|null $remember_token Laravel session remember token.
 * @property string|null $auth0_sub Auth0 subject identifier (e.g. "google-oauth2|123"); null for legacy accounts.
 * @property bool $email_verified Whether the user has confirmed their e-mail address.
 * @property \Illuminate\Support\Carbon|null $email_verified_at Timestamp of successful verification; null until verified.
 * @property \Illuminate\Support\Carbon|null $email_verification_sent_at Timestamp of the most recent verification e-mail dispatch; used for resend rate-limiting (2-minute window).
 * @property array<string, string>|null $onboarding Dismissed onboarding flags, keyed by flag name (see self::ONBOARDING_KEYS) and mapped to the ISO-8601 instant of dismissal; null until the user dismisses their first.
 * @property bool $weekly_digest_enabled Opt-in do digest semanal de cliques (default true); desligado pelo link assinado de unsubscribe.
 * @property \Illuminate\Support\Carbon|null $weekly_digest_sent_at At-most-once claim of SendWeeklyDigestEmailJob — timestamp of the last digest send (see that class's docblock).
 * @property \Illuminate\Support\Carbon|null $onboarding_tips_sent_at At-most-once claim of SendOnboardingTipsEmailJob — timestamp of the third-day tips email (see that class's docblock); null until it goes out.
 * @property bool $is_admin Gate do painel /admin (default false). Fora do $fillable de propósito — promoção só via escrita explícita (tinker). Checado do banco a cada request pelo middleware EnsureUserIsAdmin.
 * @property \Illuminate\Support\Carbon|null $last_login_at Último login (email/senha ou Auth0 exchange); null até o primeiro login pós-deploy. Base de WAU/MAU do admin.
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Link>                        $links
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmailVerificationToken>      $emailVerificationTokens
 * @property-read \App\Models\UserSubdomain|null $subdomain
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken>    $tokens API keys da API pública (Sanctum).
 */
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'password_changed_at',
        'email_verified',
        'email_verified_at',
        'email_verification_sent_at',
        'auth0_sub',
        'onboarding',
        'signup_attribution',
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
     * Contas de demonstração do produto — excluídas de TODA métrica de cliques
     * e de todo e-mail de retenção (o volume demo já inverteu geografia e
     * tendência em jul/2026). Fonte de verdade única da lista.
     *
     * @var list<int>
     */
    public const DEMO_ACCOUNT_IDS = [40, 41, 45];

    /**
     * Restringe a query aos usuários que podem receber e-mails de retenção
     * (digest semanal, marco de cliques, winback, dicas de onboarding).
     *
     * Elegível = verificado + inscrito (weekly_digest_enabled, que por decisão
     * de produto vale para TODOS os e-mails de retenção, não só o digest) +
     * fora das contas demo. O predicado de verificação é o equivalente SQL de
     * {@see self::hasVerifiedEmail()}: auth0_sub preenchido OU (email_verified
     * E email_verified_at).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeEligibleForRetentionEmails(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('weekly_digest_enabled', true)
            ->whereNotIn('id', self::DEMO_ACCOUNT_IDS)
            ->where(function ($inner) {
                $inner->whereNotNull('auth0_sub')
                    ->orWhere(fn ($q) => $q->where('email_verified', true)->whereNotNull('email_verified_at'));
            });
    }

    /**
     * Versão em memória de {@see self::scopeEligibleForRetentionEmails()}, para
     * o guard dos jobs de envio — o estado pode ter mudado entre o disparo e a
     * execução (opt-out clicado no meio do caminho, por exemplo).
     */
    public function isEligibleForRetentionEmails(): bool
    {
        return $this->weekly_digest_enabled
            && ! in_array($this->id, self::DEMO_ACCOUNT_IDS, true)
            && $this->hasVerifiedEmail();
    }

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
     * `pwd_ts`: epoch (segundos) de password_changed_at, ou 0 se a senha nunca
     * foi trocada (inclui usuários Auth0, que não têm senha local). O
     * ApiAuthenticate compara este claim com o valor atual do banco — tokens
     * emitidos antes de um reset/troca de senha carregam o epoch antigo e são
     * rejeitados com 401. O claim está em `persistent_claims` (config/jwt.php)
     * para sobreviver a JWTAuth::refresh().
     *
     * @return array{pwd_ts: int}
     */
    public function getJWTCustomClaims()
    {
        return [
            'pwd_ts' => $this->password_changed_at?->getTimestamp() ?? 0,
        ];
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
        // Same reasoning: at-most-once claim of SendWeeklyDigestEmailJob.
        // (weekly_digest_enabled stays visible — it's the opt-out state the
        // profile UI will need.)
        'weekly_digest_sent_at',
        // Same reasoning: at-most-once claim of SendOnboardingTipsEmailJob.
        'onboarding_tips_sent_at',
        // Internal anchor for JWT invalidation (pwd_ts claim) — bookkeeping,
        // not API surface; keep it out of serialized user responses.
        'password_changed_at',
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
            'weekly_digest_enabled' => 'boolean',
            'weekly_digest_sent_at' => 'datetime',
            'onboarding_tips_sent_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'email_verified' => 'boolean',
            'password' => 'hashed',
            'onboarding' => 'array',
            'signup_attribution' => 'array',
            'is_admin' => 'boolean',
            'last_login_at' => 'datetime',
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
