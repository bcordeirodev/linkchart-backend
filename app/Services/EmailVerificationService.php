<?php

namespace App\Services;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailVerificationService
{
    private EmailService $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Enviar email de verificação
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

            // Gerar link de verificação
            $verificationUrl = $this->generateVerificationUrl($token->token);

            // Preparar dados para o template
            $emailData = [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'verification_url' => $verificationUrl,
                'token' => $token->token,
                'expires_at' => $token->expires_at->format('d/m/Y H:i'),
                'app_name' => config('app.name', 'Link Charts'),
                'app_url' => config('app.url'),
                'timestamp' => now()->format('d/m/Y H:i:s'),
            ];

            // Enviar email usando SendGrid API
            $result = $this->emailService->sendEmailViaSendGridAPI(
                $user->email,
                'Verificação de Email - '.config('app.name'),
                $this->getVerificationEmailTemplate($emailData),
                $this->getVerificationEmailTextContent($emailData),
                $user->name
            );

            if ($result['success']) {
                // Marcar que email foi enviado
                $user->markVerificationEmailSent();

                Log::info('Email de verificação enviado', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'token_id' => $token->id,
                    'method' => 'SendGrid API',
                ]);

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
            Log::error('Erro ao enviar email de verificação', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao enviar email de verificação',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verificar email usando token
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

            Log::info('Email verificado com sucesso', [
                'user_id' => $user->id,
                'email' => $user->email,
                'token_id' => $verificationToken->id,
            ]);

            return [
                'success' => true,
                'message' => 'Email verificado com sucesso!',
                'type' => 'verified',
                'user' => $user,
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao verificar email', [
                'token' => $token,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao verificar email',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Enviar email de recuperação de senha
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

            // Gerar link de recuperação
            $resetUrl = $this->generatePasswordResetUrl($token->token);

            // Preparar dados para o template
            $emailData = [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'reset_url' => $resetUrl,
                'token' => $token->token,
                'expires_at' => $token->expires_at->format('d/m/Y H:i'),
                'app_name' => config('app.name', 'Link Charts'),
                'app_url' => config('app.url'),
                'timestamp' => now()->format('d/m/Y H:i:s'),
            ];

            // Enviar email usando SendGrid API
            $result = $this->emailService->sendEmailViaSendGridAPI(
                $user->email,
                'Recuperação de Senha - '.config('app.name'),
                $this->getPasswordResetEmailTemplate($emailData),
                $this->getPasswordResetEmailTextContent($emailData),
                $user->name
            );

            if ($result['success']) {
                Log::info('Email de recuperação de senha enviado', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'token_id' => $token->id,
                    'method' => 'SendGrid API',
                ]);
            }

            // Sempre retornar sucesso por segurança
            return [
                'success' => true,
                'message' => 'Se o email existir em nossa base, você receberá instruções para redefinir sua senha',
                'type' => 'email_sent',
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de recuperação de senha', [
                'email' => $email,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao enviar email de recuperação',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Redefinir senha usando token
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

            // Atualizar senha
            $user->update([
                'password' => bcrypt($newPassword),
            ]);

            // Marcar token como usado
            $resetToken->markAsUsed();

            Log::info('Senha redefinida com sucesso', [
                'user_id' => $user->id,
                'email' => $user->email,
                'token_id' => $resetToken->id,
            ]);

            return [
                'success' => true,
                'message' => 'Senha redefinida com sucesso!',
                'type' => 'password_reset',
                'user' => $user,
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao redefinir senha', [
                'token' => $token,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

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
