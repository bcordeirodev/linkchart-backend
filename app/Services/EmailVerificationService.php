<?php

namespace App\Services;

use App\Jobs\SendWelcomeEmailJob;
use App\Logging\AppLogger;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Handles transactional email flows for account verification and password reset.
 *
 * Delegates delivery to EmailService::sendTransactionalEmail (provedor configurável — Brevo default, SendGrid como rollback).
 * Token persistence is handled by EmailVerificationToken model:
 *   - Email verification tokens expire after 24 hours.
 *   - Password reset tokens expire after 1 hour.
 *   - Tokens are hashed at rest: the raw token lives only in the outgoing
 *     email (read from $token->plainTextToken right after creation); the
 *     database stores sha256(raw), and lookups hash before comparing.
 *
 * Known issue (deferred, R-18 from audit): token creation is NOT wrapped in a
 * database transaction. A partial failure (token created but email not sent)
 * leaves an orphaned token. Future hardening should wrap the create + send in a
 * single transaction with rollback on delivery failure.
 *
 * Side effects: writes to email_verification_tokens table; logs via AppLogger
 * (channel: auth for verification, errors on failures).
 */
class EmailVerificationService
{
    private EmailService $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Sends an email verification link to the given user.
     *
     * Enforces a 2-minute resend cooldown via User::canResendVerificationEmail().
     * Creates an EmailVerificationToken (TYPE_EMAIL_VERIFICATION, 24h TTL),
     * renders the verification template (resources/views/emails/verification.blade.php),
     * and delivers via the configured transactional provider.
     *
     * Side effects: writes to email_verification_tokens; calls
     * User::markVerificationEmailSent(); logs via AppLogger::authEmailVerificationSent.
     *
     * @param  User  $user  The user to verify.
     * @param  Request|null  $request  Used to capture IP + User-Agent for the token record.
     * @return array{success: bool, message: string, email?: string, expires_at?: string, type?: string, error?: string}
     */
    public function sendVerificationEmail(User $user, ?Request $request = null): array
    {
        try {
            // Verificar rate limiting
            if (! $user->canResendVerificationEmail()) {
                return [
                    'success' => false,
                    'message' => 'Aguarde 2 minutos antes de solicitar um novo email de verificação',
                    'type' => 'rate_limit',
                ];
            }

            // Criar token de verificação
            $token = EmailVerificationToken::createEmailVerificationToken(
                $user->email,
                $request ? $request->ip() : null,
                $request ? $request->userAgent() : null
            );

            // Gerar link de verificação — usa o token CRU, que existe apenas
            // nesta instância recém-criada ($plainTextToken). O banco guarda
            // somente o digest sha256 dele.
            $verificationUrl = $this->generateVerificationUrl($token->plainTextToken);

            // Preparar dados para o template
            $emailData = [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'verification_url' => $verificationUrl,
                'token' => $token->plainTextToken,
                'expires_at' => $token->expires_at->format('d/m/Y H:i'),
                'app_name' => config('app.name', 'Link Charts'),
                'app_url' => config('app.url'),
                'timestamp' => now()->format('d/m/Y H:i:s'),
            ];

            // Enviar email usando o provedor transacional configurado
            $result = $this->emailService->sendTransactionalEmail(
                $user->email,
                'Verificação de Email - '.config('app.name'),
                $this->getVerificationEmailTemplate($emailData),
                $this->getVerificationEmailTextContent($emailData),
                $user->name,
                'verification'
            );

            if ($result['success']) {
                // Marcar que email foi enviado
                $user->markVerificationEmailSent();

                AppLogger::authEmailVerificationSent($user->email, $user->id);

                return [
                    'success' => true,
                    'message' => 'Email de verificação enviado com sucesso',
                    'email' => $user->email,
                    'expires_at' => $token->expires_at->toISOString(),
                ];
            }

            return [
                'success' => false,
                'message' => 'Erro ao enviar email de verificação: '.$result['message'],
                'error' => $result['error'] ?? null,
            ];

        } catch (\Exception $e) {
            AppLogger::authError('email_verification_send', $e, ['email' => $user->email ?? null]);

            return [
                'success' => false,
                'message' => 'Erro interno ao enviar email de verificação',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verifies an email address using a previously issued verification token.
     *
     * Looks up a valid (non-expired, non-used) TYPE_EMAIL_VERIFICATION token,
     * marks the user's email as verified, and marks the token as used.
     * Idempotent: if the email is already verified, marks the token as used
     * and returns type=already_verified.
     *
     * Side effects: updates users.email_verified_at; updates token as used;
     * dispatches SendWelcomeEmailJob (second trigger point — the job itself decides
     * whether to send); logs via AppLogger::authEmailVerified.
     *
     * @param  string  $token  Raw token string from the verification link.
     * @return array<string, mixed> Keys: success (bool), message (string), type (string, absent on internal errors), user (User, optional), error (string, optional).
     */
    public function verifyEmail(string $token): array
    {
        try {
            // Buscar token válido
            $verificationToken = EmailVerificationToken::findValidToken(
                $token,
                EmailVerificationToken::TYPE_EMAIL_VERIFICATION
            );

            if (! $verificationToken) {
                return [
                    'success' => false,
                    'message' => 'Token de verificação inválido ou expirado',
                    'type' => 'invalid_token',
                ];
            }

            // Buscar usuário
            $user = User::where('email', $verificationToken->email)->first();

            if (! $user) {
                return [
                    'success' => false,
                    'message' => 'Usuário não encontrado',
                    'type' => 'user_not_found',
                ];
            }

            // Verificar se já está verificado
            if ($user->hasVerifiedEmail()) {
                $verificationToken->markAsUsed();

                return [
                    'success' => true,
                    'message' => 'Email já estava verificado',
                    'type' => 'already_verified',
                    'user' => $user,
                ];
            }

            // Marcar email como verificado
            $user->markEmailAsVerified();
            $verificationToken->markAsUsed();

            // Segundo ponto de disparo das boas-vindas: o usuário de e-mail/senha
            // nasce não-verificado, então o dispatch do UserObserver saiu sem enviar.
            // Agora que hasVerifiedEmail() é true, o job entrega (uma vez só — o claim
            // em welcome_email_sent_at protege contra duplicata).
            SendWelcomeEmailJob::dispatch($user->id);

            AppLogger::authEmailVerified($user->id);

            return [
                'success' => true,
                'message' => 'Email verificado com sucesso!',
                'type' => 'verified',
                'user' => $user,
            ];

        } catch (\Exception $e) {
            AppLogger::authError('email_verify', $e);

            return [
                'success' => false,
                'message' => 'Erro interno ao verificar email',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sends a password reset link to the given email address.
     *
     * Always returns a success-shaped response even when the email is not found
     * (security: prevents user enumeration). Creates an EmailVerificationToken
     * (TYPE_PASSWORD_RESET, 1h TTL) and delivers via the configured transactional provider.
     *
     * Side effects: may write to email_verification_tokens; logs via
     * AppLogger::authPasswordResetRequested on successful delivery.
     *
     * @param  string  $email  Target email address.
     * @param  Request|null  $request  Used to capture IP + User-Agent for the token record.
     * @return array<string, mixed> Keys: success (bool), message (string), type (string, absent on internal errors), error (string, optional).
     */
    public function sendPasswordResetEmail(string $email, ?Request $request = null): array
    {
        try {
            // Buscar usuário
            $user = User::where('email', $email)->first();

            if (! $user) {
                // Por segurança, não revelar se o email existe ou não
                return [
                    'success' => true,
                    'message' => 'Se o email existir em nossa base, você receberá instruções para redefinir sua senha',
                    'type' => 'email_sent',
                ];
            }

            // Criar token de recuperação
            $token = EmailVerificationToken::createPasswordResetToken(
                $email,
                $request ? $request->ip() : null,
                $request ? $request->userAgent() : null
            );

            // Gerar link de recuperação — usa o token CRU, que existe apenas
            // nesta instância recém-criada ($plainTextToken). O banco guarda
            // somente o digest sha256 dele.
            $resetUrl = $this->generatePasswordResetUrl($token->plainTextToken);

            // Preparar dados para o template
            $emailData = [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'reset_url' => $resetUrl,
                'token' => $token->plainTextToken,
                'expires_at' => $token->expires_at->format('d/m/Y H:i'),
                'app_name' => config('app.name', 'Link Charts'),
                'app_url' => config('app.url'),
                'timestamp' => now()->format('d/m/Y H:i:s'),
            ];

            // Enviar email usando o provedor transacional configurado
            $result = $this->emailService->sendTransactionalEmail(
                $user->email,
                'Recuperação de Senha - '.config('app.name'),
                $this->getPasswordResetEmailTemplate($emailData),
                $this->getPasswordResetEmailTextContent($emailData),
                $user->name,
                'password_reset'
            );

            if ($result['success']) {
                AppLogger::authPasswordResetRequested($email);
            }

            // Sempre retornar sucesso por segurança
            return [
                'success' => true,
                'message' => 'Se o email existir em nossa base, você receberá instruções para redefinir sua senha',
                'type' => 'email_sent',
            ];

        } catch (\Exception $e) {
            AppLogger::authError('password_reset_send', $e, ['email' => $email ?? null]);

            return [
                'success' => false,
                'message' => 'Erro interno ao enviar email de recuperação',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resets the user's password using a valid password-reset token.
     *
     * Validates a TYPE_PASSWORD_RESET token (1h TTL), updates the user's
     * bcrypt-hashed password, and marks the token as used.
     * NOT idempotent — each call updates the password and consumes the token.
     *
     * Also stamps users.password_changed_at, which invalidates every JWT
     * issued before the reset (ApiAuthenticate compares the `pwd_ts` claim
     * against it) — a stolen token dies when the victim recovers the account.
     * No new JWT is issued here: this flow is unauthenticated and the user
     * logs in again with the new password.
     *
     * Side effects: writes users.password + users.password_changed_at; marks
     * token as used; logs via AppLogger::authPasswordResetCompleted.
     *
     * @param  string  $token  Raw reset token from the password-reset link.
     * @param  string  $newPassword  The new plaintext password (will be bcrypt-hashed).
     * @return array<string, mixed> Keys: success (bool), message (string), type (string, absent on internal errors), user (User, optional), error (string, optional).
     */
    public function resetPassword(string $token, string $newPassword): array
    {
        try {
            // Buscar token válido
            $resetToken = EmailVerificationToken::findValidToken(
                $token,
                EmailVerificationToken::TYPE_PASSWORD_RESET
            );

            if (! $resetToken) {
                return [
                    'success' => false,
                    'message' => 'Token de recuperação inválido ou expirado',
                    'type' => 'invalid_token',
                ];
            }

            // Buscar usuário
            $user = User::where('email', $resetToken->email)->first();

            if (! $user) {
                return [
                    'success' => false,
                    'message' => 'Usuário não encontrado',
                    'type' => 'user_not_found',
                ];
            }

            // Atualizar senha. password_changed_at é a âncora da invalidação
            // de JWT: tokens emitidos antes deste instante carregam um claim
            // `pwd_ts` antigo e passam a ser rejeitados pelo ApiAuthenticate.
            $user->update([
                'password' => bcrypt($newPassword),
                'password_changed_at' => now(),
            ]);

            // Marcar token como usado
            $resetToken->markAsUsed();

            AppLogger::authPasswordResetCompleted($user->id);

            return [
                'success' => true,
                'message' => 'Senha redefinida com sucesso!',
                'type' => 'password_reset',
                'user' => $user,
            ];

        } catch (\Exception $e) {
            AppLogger::authError('password_reset', $e);

            return [
                'success' => false,
                'message' => 'Erro interno ao redefinir senha',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Gerar URL de verificação
     */
    private function generateVerificationUrl(string $token): string
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));

        return $frontendUrl.'/verify-email?token='.$token;
    }

    /**
     * Gerar URL de recuperação de senha
     */
    private function generatePasswordResetUrl(string $token): string
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));

        return $frontendUrl.'/reset-password?token='.$token;
    }

    /**
     * Template HTML para email de verificação
     */
    private function getVerificationEmailTemplate(array $data): string
    {
        return view('emails.verification', $data)->render();
    }

    /**
     * Conteúdo de texto para email de verificação
     */
    private function getVerificationEmailTextContent(array $data): string
    {
        return view('emails.verification-text', $data)->render();
    }

    /**
     * Template HTML para email de recuperação de senha
     */
    private function getPasswordResetEmailTemplate(array $data): string
    {
        return view('emails.password-reset', $data)->render();
    }

    /**
     * Conteúdo de texto para email de recuperação de senha
     */
    private function getPasswordResetEmailTextContent(array $data): string
    {
        return view('emails.password-reset-text', $data)->render();
    }
}
