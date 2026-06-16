<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A single-use token for e-mail verification or password reset.
 *
 * Tokens are SHA-256 hashes of a 60-character random string. Two token types
 * share this table, distinguished by the `type` column. On creation, any
 * existing unused tokens of the same type for the same e-mail are soft-invalidated
 * (marked used) before the new one is created. The record is associated with a
 * User via the e-mail address rather than a foreign key, because password reset
 * tokens may be created before login.
 *
 * Fillable: email, token, type, expires_at, used, used_at, ip_address, user_agent.
 *
 * Casts: expires_at → datetime, used_at → datetime, used → boolean.
 *
 * Constants:
 *   TYPE_EMAIL_VERIFICATION = 'email_verification' (24-hour window).
 *   TYPE_PASSWORD_RESET     = 'password_reset'     (1-hour window).
 *
 * @property int $id
 * @property string $email E-mail address the token was issued for (indexed).
 * @property string $token SHA-256 hex digest (64 chars, unique).
 * @property string $type Token purpose: 'email_verification' | 'password_reset'.
 * @property \Illuminate\Support\Carbon $expires_at Hard expiry timestamp; token is invalid after this.
 * @property bool $used True once the token has been consumed or superseded.
 * @property \Illuminate\Support\Carbon|null $used_at Timestamp when the token was consumed; null if still unused.
 * @property string|null $ip_address Client IP at the time of issuance (up to 45 chars).
 * @property string|null $user_agent Client User-Agent at the time of issuance (text).
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User|null $user  The user with this e-mail address (belongsTo User via email ↔ email).
 */
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
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'used' => 'boolean',
    ];

    // Tipos de token
    const TYPE_EMAIL_VERIFICATION = 'email_verification';

    const TYPE_PASSWORD_RESET = 'password_reset';

    /**
     * Generate a cryptographically safe token string.
     *
     * Returns a SHA-256 hex digest (64 chars) of 60 random bytes produced by
     * Str::random(). Suitable for use as the unique `token` column value.
     */
    public static function generateToken(): string
    {
        return hash('sha256', Str::random(60));
    }

    /**
     * Create a new e-mail verification token for the given address.
     *
     * Any existing unused email_verification tokens for the same e-mail are
     * invalidated first. The new token expires in 24 hours.
     *
     * @param  string|null  $ipAddress  Client IP to record for audit purposes.
     * @param  string|null  $userAgent  Client User-Agent to record for audit purposes.
     */
    public static function createEmailVerificationToken(
        string $email,
        ?string $ipAddress = null,
        ?string $userAgent = null
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
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Create a new password-reset token for the given address.
     *
     * Any existing unused password_reset tokens for the same e-mail are
     * invalidated first. The new token expires in 1 hour.
     *
     * @param  string|null  $ipAddress  Client IP to record for audit purposes.
     * @param  string|null  $userAgent  Client User-Agent to record for audit purposes.
     */
    public static function createPasswordResetToken(
        string $email,
        ?string $ipAddress = null,
        ?string $userAgent = null
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
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Mark this token as consumed; persists immediately (sets used = true, used_at = now()).
     */
    public function markAsUsed(): void
    {
        $this->update([
            'used' => true,
            'used_at' => now(),
        ]);
    }

    /**
     * Find an unused, unexpired token matching the given hash and type.
     *
     * Returns null if no matching valid token exists. Used by AuthController
     * to verify incoming reset/verification requests.
     *
     * @param  string  $type  One of TYPE_EMAIL_VERIFICATION or TYPE_PASSWORD_RESET.
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
     * Delete all expired or already-used tokens from the table.
     *
     * Intended for periodic cleanup (e.g. an Artisan command or scheduled job).
     * Returns the number of rows deleted.
     */
    public static function cleanExpiredTokens(): int
    {
        return self::where('expires_at', '<', now())
            ->orWhere('used', true)
            ->delete();
    }

    /**
     * The user associated with this token's e-mail address (belongsTo User via email ↔ email).
     *
     * The join is on the e-mail string rather than a traditional integer FK, because tokens
     * may be created before the user record exists in some edge cases.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }
}
