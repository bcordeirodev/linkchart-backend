<?php

namespace App\Services;

use App\Models\User;
use App\Models\EmailVerificationToken;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

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
    public function sendVerificationEmail(User $user, Request $request = null): array
    {
        try {
            // Verificar rate limiting
            if (!$user->canResendVerificationEmail()) {
                return [
                    'success' => false,
                    'message' => 'Aguarde 2 minutos antes de solicitar um novo email de verificação',
                    'type' => 'rate_limit'
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
                'timestamp' => now()->format('d/m/Y H:i:s')
            ];

            // Enviar email usando SendGrid API
            $result = $this->emailService->sendEmailViaSendGridAPI(
                $user->email,
                'Verificação de Email - ' . config('app.name'),
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
                    'method' => 'SendGrid API'
                ]);

                return [
                    'success' => true,
                    'message' => 'Email de verificação enviado com sucesso',
                    'email' => $user->email,
                    'expires_at' => $token->expires_at->toISOString()
                ];
            }

            return [
                'success' => false,
                'message' => 'Erro ao enviar email de verificação: ' . $result['message'],
                'error' => $result['error'] ?? null
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de verificação', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao enviar email de verificação',
                'error' => $e->getMessage()
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

            if (!$verificationToken) {
                return [
                    'success' => false,
                    'message' => 'Token de verificação inválido ou expirado',
                    'type' => 'invalid_token'
                ];
            }

            // Buscar usuário
            $user = User::where('email', $verificationToken->email)->first();

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Usuário não encontrado',
                    'type' => 'user_not_found'
                ];
            }

            // Verificar se já está verificado
            if ($user->hasVerifiedEmail()) {
                $verificationToken->markAsUsed();

                return [
                    'success' => true,
                    'message' => 'Email já estava verificado',
                    'type' => 'already_verified',
                    'user' => $user
                ];
            }

            // Marcar email como verificado
            $user->markEmailAsVerified();
            $verificationToken->markAsUsed();

            Log::info('Email verificado com sucesso', [
                'user_id' => $user->id,
                'email' => $user->email,
                'token_id' => $verificationToken->id
            ]);

            return [
                'success' => true,
                'message' => 'Email verificado com sucesso!',
                'type' => 'verified',
                'user' => $user
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao verificar email', [
                'token' => $token,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao verificar email',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Enviar email de recuperação de senha
     */
    public function sendPasswordResetEmail(string $email, Request $request = null): array
    {
        try {
            // Buscar usuário
            $user = User::where('email', $email)->first();

            if (!$user) {
                // Por segurança, não revelar se o email existe ou não
                return [
                    'success' => true,
                    'message' => 'Se o email existir em nossa base, você receberá instruções para redefinir sua senha',
                    'type' => 'email_sent'
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
                'timestamp' => now()->format('d/m/Y H:i:s')
            ];

            // Enviar email usando SendGrid API
            $result = $this->emailService->sendEmailViaSendGridAPI(
                $user->email,
                'Recuperação de Senha - ' . config('app.name'),
                $this->getPasswordResetEmailTemplate($emailData),
                $this->getPasswordResetEmailTextContent($emailData),
                $user->name
            );

            if ($result['success']) {
                Log::info('Email de recuperação de senha enviado', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'token_id' => $token->id,
                    'method' => 'SendGrid API'
                ]);
            }

            // Sempre retornar sucesso por segurança
            return [
                'success' => true,
                'message' => 'Se o email existir em nossa base, você receberá instruções para redefinir sua senha',
                'type' => 'email_sent'
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de recuperação de senha', [
                'email' => $email,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao enviar email de recuperação',
                'error' => $e->getMessage()
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

            if (!$resetToken) {
                return [
                    'success' => false,
                    'message' => 'Token de recuperação inválido ou expirado',
                    'type' => 'invalid_token'
                ];
            }

            // Buscar usuário
            $user = User::where('email', $resetToken->email)->first();

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Usuário não encontrado',
                    'type' => 'user_not_found'
                ];
            }

            // Atualizar senha
            $user->update([
                'password' => bcrypt($newPassword)
            ]);

            // Marcar token como usado
            $resetToken->markAsUsed();

            Log::info('Senha redefinida com sucesso', [
                'user_id' => $user->id,
                'email' => $user->email,
                'token_id' => $resetToken->id
            ]);

            return [
                'success' => true,
                'message' => 'Senha redefinida com sucesso!',
                'type' => 'password_reset',
                'user' => $user
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao redefinir senha', [
                'token' => $token,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Erro interno ao redefinir senha',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Gerar URL de verificação
     */
    private function generateVerificationUrl(string $token): string
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));
        return $frontendUrl . '/verify-email?token=' . $token;
    }

    /**
     * Gerar URL de recuperação de senha
     */
    private function generatePasswordResetUrl(string $token): string
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));
        return $frontendUrl . '/reset-password?token=' . $token;
    }

    /**
     * Template HTML para email de verificação
     */
    private function getVerificationEmailTemplate(array $data): string
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Verificação de Email - {$data['app_name']}</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #4caf50, #66bb6a); color: white; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #ffffff; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .verify-button { display: inline-block; background: #4caf50; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
                .verify-button:hover { background: #45a049; }
                .info { background: #e8f5e8; padding: 20px; border-left: 4px solid #4caf50; margin: 20px 0; border-radius: 4px; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
                .token-info { background: #f9f9f9; padding: 15px; border-radius: 4px; margin: 15px 0; font-family: monospace; }
                .warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 15px; border-radius: 4px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✉️ {$data['app_name']}</h1>
                    <p>Verificação de Email</p>
                </div>
                <div class='content'>
                    <h2>Olá, {$data['user_name']}!</h2>

                    <p>Obrigado por se cadastrar no <strong>{$data['app_name']}</strong>! Para completar seu cadastro e começar a usar nossa plataforma, você precisa verificar seu endereço de email.</p>

                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$data['verification_url']}' class='verify-button'>
                            ✅ Verificar Email
                        </a>
                    </div>

                    <div class='info'>
                        <strong>📋 Detalhes da Verificação:</strong><br>
                        <strong>Email:</strong> {$data['user_email']}<br>
                        <strong>Válido até:</strong> {$data['expires_at']}<br>
                        <strong>Data/Hora:</strong> {$data['timestamp']}
                    </div>

                    <div class='warning'>
                        <strong>⚠️ Importante:</strong><br>
                        • Este link expira em 24 horas<br>
                        • Use apenas se você solicitou esta verificação<br>
                        • Não compartilhe este link com ninguém
                    </div>

                    <p><strong>Não consegue clicar no botão?</strong><br>
                    Copie e cole este link no seu navegador:</p>
                    <div class='token-info'>
                        {$data['verification_url']}
                    </div>

                    <p>Se você não se cadastrou no {$data['app_name']}, pode ignorar este email com segurança.</p>

                    <p><em>Este é um email automático. Não é necessário responder.</em></p>
                </div>
                <div class='footer'>
                    <p><strong>{$data['app_name']}</strong> - Sistema de Encurtamento de URLs<br>
                    <small>Desenvolvido com ❤️ usando Laravel + SendGrid</small></p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Conteúdo de texto para email de verificação
     */
    private function getVerificationEmailTextContent(array $data): string
    {
        return "
        Verificação de Email - {$data['app_name']}

        Olá, {$data['user_name']}!

        Obrigado por se cadastrar no {$data['app_name']}! Para completar seu cadastro, você precisa verificar seu endereço de email.

        Clique no link abaixo para verificar:
        {$data['verification_url']}

        Detalhes:
        - Email: {$data['user_email']}
        - Válido até: {$data['expires_at']}
        - Data/Hora: {$data['timestamp']}

        IMPORTANTE:
        - Este link expira em 24 horas
        - Use apenas se você solicitou esta verificação
        - Não compartilhe este link com ninguém

        Se você não se cadastrou no {$data['app_name']}, pode ignorar este email.

        {$data['app_name']} - Sistema de Encurtamento de URLs
        ";
    }

    /**
     * Template HTML para email de recuperação de senha
     */
    private function getPasswordResetEmailTemplate(array $data): string
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Recuperação de Senha - {$data['app_name']}</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #ff9800, #ffb74d); color: white; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #ffffff; padding: 30px; border-radius: 0 0 8px 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .reset-button { display: inline-block; background: #ff9800; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
                .reset-button:hover { background: #f57c00; }
                .info { background: #fff3e0; padding: 20px; border-left: 4px solid #ff9800; margin: 20px 0; border-radius: 4px; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
                .token-info { background: #f9f9f9; padding: 15px; border-radius: 4px; margin: 15px 0; font-family: monospace; }
                .warning { background: #ffebee; border: 1px solid #ffcdd2; color: #c62828; padding: 15px; border-radius: 4px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🔐 {$data['app_name']}</h1>
                    <p>Recuperação de Senha</p>
                </div>
                <div class='content'>
                    <h2>Olá, {$data['user_name']}!</h2>

                    <p>Recebemos uma solicitação para redefinir a senha da sua conta no <strong>{$data['app_name']}</strong>.</p>

                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$data['reset_url']}' class='reset-button'>
                            🔑 Redefinir Senha
                        </a>
                    </div>

                    <div class='info'>
                        <strong>📋 Detalhes da Solicitação:</strong><br>
                        <strong>Email:</strong> {$data['user_email']}<br>
                        <strong>Válido até:</strong> {$data['expires_at']}<br>
                        <strong>Data/Hora:</strong> {$data['timestamp']}
                    </div>

                    <div class='warning'>
                        <strong>🚨 Segurança:</strong><br>
                        • Este link expira em 1 hora<br>
                        • Use apenas se você solicitou esta recuperação<br>
                        • Não compartilhe este link com ninguém<br>
                        • Após usar, o link será invalidado
                    </div>

                    <p><strong>Não consegue clicar no botão?</strong><br>
                    Copie e cole este link no seu navegador:</p>
                    <div class='token-info'>
                        {$data['reset_url']}
                    </div>

                    <p><strong>Não solicitou esta recuperação?</strong><br>
                    Se você não solicitou a redefinição de senha, pode ignorar este email com segurança. Sua senha permanecerá inalterada.</p>

                    <p><em>Este é um email automático. Não é necessário responder.</em></p>
                </div>
                <div class='footer'>
                    <p><strong>{$data['app_name']}</strong> - Sistema de Encurtamento de URLs<br>
                    <small>Desenvolvido com ❤️ usando Laravel + SendGrid</small></p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Conteúdo de texto para email de recuperação de senha
     */
    private function getPasswordResetEmailTextContent(array $data): string
    {
        return "
        Recuperação de Senha - {$data['app_name']}

        Olá, {$data['user_name']}!

        Recebemos uma solicitação para redefinir a senha da sua conta no {$data['app_name']}.

        Clique no link abaixo para redefinir sua senha:
        {$data['reset_url']}

        Detalhes:
        - Email: {$data['user_email']}
        - Válido até: {$data['expires_at']}
        - Data/Hora: {$data['timestamp']}

        IMPORTANTE:
        - Este link expira em 1 hora
        - Use apenas se você solicitou esta recuperação
        - Não compartilhe este link com ninguém
        - Após usar, o link será invalidado

        Se você não solicitou esta recuperação, pode ignorar este email.

        {$data['app_name']} - Sistema de Encurtamento de URLs
        ";
    }
}
