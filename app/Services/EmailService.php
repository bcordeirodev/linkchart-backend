<?php

namespace App\Services;

use App\Logging\AppLogger;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Low-level email transport service supporting two delivery paths: the Brevo HTTP API
 * (bypasses SMTP port 587, uses HTTPS port 443) and Laravel's native Mail facade (SMTP).
 *
 * The Brevo API path is the production method and is used by EmailVerificationService
 * for all transactional emails. The Laravel Mail path is kept for local/dev diagnostics.
 *
 * Transport configuration lives in config/mail.php and config/services.php
 * (key: services.brevo.api_key / services.brevo.from.*).
 *
 * Side effects: logs every send attempt and failure via AppLogger::emailSent /
 * AppLogger::emailFailed / AppLogger::emailTestFailed (channel: app).
 */
class EmailService
{
    /**
     * Sends a transactional email via the Brevo HTTP API.
     *
     * This is the method every caller should use (EmailVerificationService,
     * SendWelcomeEmailJob, SendWeeklyDigestEmailJob and the retention jobs,
     * TestEmailCommand). It used to route between two providers; the second
     * path was retired by the product owner on 2026-08-05, leaving Brevo as
     * the single transport. The signature is unchanged so callers did not have
     * to move.
     *
     * Same contract as the concrete sender: returns an error payload instead
     * of throwing, so callers MUST inspect `success` (see SendWelcomeEmailJob
     * for why silently ignoring it loses emails).
     *
     * @param  string  $toEmail  Recipient email address.
     * @param  string  $subject  Email subject line.
     * @param  string  $htmlContent  HTML body.
     * @param  string|null  $textContent  Optional plain-text fallback body.
     * @param  string|null  $toName  Optional recipient display name.
     * @param  string  $type  Semantic email type for logs (welcome, verification, password_reset, weekly_digest, milestone, winback, onboarding_tips).
     * @return array{success: bool, message: string, to?: string, method?: string, status_code?: int, error?: string}
     */
    public function sendTransactionalEmail(string $toEmail, string $subject, string $htmlContent, ?string $textContent = null, ?string $toName = null, string $type = 'unknown'): array
    {
        return $this->sendEmailViaBrevoAPI($toEmail, $subject, $htmlContent, $textContent, $toName, $type);
    }

    /**
     * Sends an email via the Brevo v3 HTTP API (POST /v3/smtp/email).
     *
     * Uses Laravel's Http client directly — the API surface needed here is a
     * single JSON POST, not worth another SDK in composer. Never throws:
     * returns an error payload on any failure (missing/placeholder key,
     * non-2xx response, network error).
     *
     * Side effects: logs via AppLogger::emailSent on success and
     * AppLogger::emailFailed on failure (channel: app).
     *
     * @param  string  $toEmail  Recipient email address.
     * @param  string  $subject  Email subject line.
     * @param  string  $htmlContent  HTML body.
     * @param  string|null  $textContent  Optional plain-text fallback body (omitted from the payload when empty — the API rejects empty strings).
     * @param  string|null  $toName  Optional recipient display name.
     * @param  string  $type  Semantic email type for logs (welcome, verification, password_reset, weekly_digest, milestone, winback, onboarding_tips). Also sent as a Brevo tag so webhook events can be attributed per campaign.
     * @return array{success: bool, message: string, to?: string, method: string, status_code?: int, message_id?: string|null, error?: string}
     */
    public function sendEmailViaBrevoAPI(string $toEmail, string $subject, string $htmlContent, ?string $textContent = null, ?string $toName = null, string $type = 'unknown'): array
    {
        try {
            $apiKey = config('services.brevo.api_key');

            if (empty($apiKey) || $apiKey === 'BREVO_API_KEY_PLACEHOLDER') {
                throw new RuntimeException('Brevo API Key não configurada');
            }

            $payload = [
                'sender' => [
                    'name' => config('services.brevo.from.name', config('mail.from.name')),
                    'email' => config('services.brevo.from.email', config('mail.from.address')),
                ],
                'to' => [['email' => $toEmail, 'name' => $toName ?? $toEmail]],
                'subject' => $subject,
                'htmlContent' => $htmlContent,
                // O tipo semântico ($type) vira tag Brevo: é ela que volta nos eventos do
                // webhook e permite montar o funil enviado→aberto→clicado por campanha.
                'tags' => [$type],
            ];

            if ($textContent !== null && $textContent !== '') {
                $payload['textContent'] = $textContent;
            }

            $response = Http::withHeaders(['api-key' => $apiKey])
                ->timeout(15)
                ->post('https://api.brevo.com/v3/smtp/email', $payload);

            if (! $response->successful()) {
                $error = sprintf(
                    'Brevo API recusou o envio (HTTP %d): %s',
                    $response->status(),
                    $response->json('message') ?? $response->body(),
                );

                AppLogger::emailFailed($toEmail, $type, new RuntimeException($error));

                return [
                    'success' => false,
                    'message' => $error,
                    'error' => $error,
                    'method' => 'Brevo API',
                    'status_code' => $response->status(),
                ];
            }

            AppLogger::emailSent($toEmail, $type, 'brevo');

            return [
                'success' => true,
                'message' => 'Email enviado com sucesso via Brevo API',
                'to' => $toEmail,
                'method' => 'Brevo API',
                'status_code' => $response->status(),
                'message_id' => $response->json('messageId'),
            ];
        } catch (\Throwable $e) {
            AppLogger::emailFailed($toEmail, $type, $e);

            return [
                'success' => false,
                'message' => 'Erro ao enviar email via Brevo API: '.$e->getMessage(),
                'error' => $e->getMessage(),
                'method' => 'Brevo API',
            ];
        }
    }

    /**
     * Sends a test email via Laravel's native Mail facade (SMTP path).
     *
     * Uses the mailer configured in config/mail.php. Primarily for local/dev
     * validation that SMTP credentials and port are correct.
     * Side effects: logs via AppLogger::emailSent / AppLogger::emailFailed.
     *
     * @param  string  $toEmail  Recipient address.
     * @param  string|null  $toName  Optional display name.
     * @return array{success: bool, message: string, to?: string, mailer?: string, error?: string}
     */
    public function sendTestEmail(string $toEmail, ?string $toName = null): array
    {
        try {
            $data = [
                'name' => $toName ?? $toEmail,
                'email' => $toEmail,
                'timestamp' => now()->format('d/m/Y H:i:s'),
                'environment' => config('app.env'),
                'smtp_host' => config('mail.mailers.smtp.host'),
                'smtp_port' => config('mail.mailers.smtp.port'),
                'encryption' => config('mail.mailers.smtp.port') == 465 ? 'SSL' : 'TLS',
            ];

            Mail::send([], [], function (Message $message) use ($toEmail, $toName, $data) {
                $message->to($toEmail, $toName ?? $toEmail)
                    ->subject('Teste de Email - Link Charts')
                    ->html($this->getTestEmailTemplate($data));
            });

            AppLogger::emailSent($toEmail, 'test', 'laravel_mail');

            return [
                'success' => true,
                'message' => 'Email enviado com sucesso via Laravel Mail',
                'to' => $toEmail,
                'mailer' => config('mail.default'),
            ];

        } catch (\Exception $e) {
            AppLogger::emailFailed($toEmail, 'test', $e);

            return [
                'success' => false,
                'message' => 'Erro ao enviar email: '.$e->getMessage(),
                'error' => $e->getMessage(),
                'mailer' => config('mail.default'),
            ];
        }
    }

    /**
     * Validates the Laravel Mail SMTP configuration by attempting a test send.
     *
     * Checks that host, username, and password are set before attempting. On
     * exception, logs via AppLogger::emailTestFailed.
     *
     * @return array{success: bool, message: string, config: array, error?: string}
     */
    public function testConnection(): array
    {
        try {
            // Verificar configurações básicas
            $config = $this->getMailConfiguration();

            if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
                return [
                    'success' => false,
                    'message' => 'Configurações de email incompletas',
                    'config' => $config,
                ];
            }

            // Tentar enviar um email de teste simples
            Mail::raw('Teste de conectividade Laravel Mail', function (Message $message) {
                $message->to('test@example.com')
                    ->subject('Teste de Conectividade');
            });

            return [
                'success' => true,
                'message' => 'Configuração Laravel Mail válida',
                'config' => $config,
            ];

        } catch (\Exception $e) {
            AppLogger::emailTestFailed($e, ['provider' => 'laravel_mail']);

            return [
                'success' => false,
                'message' => 'Erro na configuração: '.$e->getMessage(),
                'error' => $e->getMessage(),
                'config' => $this->getMailConfiguration(),
            ];
        }
    }

    /**
     * Returns a sanitized snapshot of the current Laravel Mail / SMTP configuration.
     *
     * Password is masked as '***CONFIGURADO***' if present. Suitable for diagnostics.
     *
     * @return array{default_mailer: string, host: string|null, port: int|null, username: string|null, password: string, encryption: string, from_address: string|null, from_name: string|null, timeout: mixed, verify_peer: mixed}
     */
    public function getMailConfiguration(): array
    {
        return [
            'default_mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'username' => config('mail.mailers.smtp.username'),
            'password' => config('mail.mailers.smtp.password') ? '***CONFIGURADO***' : 'NÃO CONFIGURADO',
            'encryption' => config('mail.mailers.smtp.port') == 465 ? 'SSL' : 'TLS',
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'timeout' => config('mail.mailers.smtp.timeout', 'padrão'),
            'verify_peer' => config('mail.mailers.smtp.verify_peer', 'padrão'),
        ];
    }

    /**
     * Template HTML para email de teste
     */
    private function getTestEmailTemplate($data)
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Teste de Email - Link Charts</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #1976d2, #42a5f5); color: white; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                .success { background: #4caf50; color: white; padding: 15px; border-radius: 4px; margin: 20px 0; text-align: center; }
                .info { background: #e3f2fd; padding: 20px; border-left: 4px solid #1976d2; margin: 20px 0; }
                .config-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                .config-table th, .config-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
                .config-table th { background: #f5f5f5; font-weight: bold; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
                .badge { background: #1976d2; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🚀 Link Charts</h1>
                    <p>Sistema de Encurtamento de URLs</p>
                    <span class='badge'>Laravel Mail Nativo (SMTP)</span>
                </div>
                <div class='content'>
                    <h2>✅ Email Laravel Mail Funcionando!</h2>

                    <p>Olá <strong>{$data['name']}</strong>,</p>

                    <div class='success'>
                        <strong>🎉 SUCESSO!</strong><br>
                        Laravel Mail + SMTP configurado e funcionando perfeitamente!
                    </div>

                    <div class='info'>
                        <strong>📋 Detalhes da Configuração:</strong><br>
                        <table class='config-table'>
                            <tr><th>Data/Hora</th><td>{$data['timestamp']}</td></tr>
                            <tr><th>Servidor SMTP</th><td>{$data['smtp_host']}</td></tr>
                            <tr><th>Porta</th><td>{$data['smtp_port']}</td></tr>
                            <tr><th>Encryption</th><td>{$data['encryption']}</td></tr>
                            <tr><th>Ambiente</th><td>{$data['environment']}</td></tr>
                            <tr><th>Mailer</th><td>Laravel Mail (nativo)</td></tr>
                        </table>
                    </div>

                    <p><strong>🔧 Melhorias Implementadas:</strong></p>
                    <ul>
                        <li>✅ Removido PHPMailer (conflito resolvido)</li>
                        <li>✅ Usando Laravel Mail nativo</li>
                        <li>✅ Timeout e verificação SSL configurados</li>
                        <li>✅ Logs detalhados para debug</li>
                    </ul>

                    <p>O sistema de email está agora <strong>100% funcional</strong> e otimizado para produção!</p>

                    <p><em>Este é um email automático de teste. Não é necessário responder.</em></p>
                </div>
                <div class='footer'>
                    <p><strong>Link Charts</strong> - Sistema de Encurtamento de URLs<br>
                    <small>Desenvolvido com ❤️ usando Laravel</small></p>
                </div>
            </div>
        </body>
        </html>";
    }
}
