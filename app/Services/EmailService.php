<?php

namespace App\Services;

use App\Logging\AppLogger;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use SendGrid;
use SendGrid\Mail\Mail as SendGridMail;

/**
 * Low-level email transport service supporting two delivery paths: the SendGrid HTTP API
 * (bypasses SMTP port 587, uses HTTPS port 443) and Laravel's native Mail facade (SMTP).
 *
 * The SendGrid API path is the preferred production method and is used by
 * EmailVerificationService for all transactional emails. The Laravel Mail path is kept
 * as a fallback and for local/dev testing.
 *
 * Transport configuration lives in config/mail.php and config/services.php
 * (key: services.sendgrid.api_key / services.sendgrid.from.*).
 *
 * Side effects: logs every send attempt and failure via AppLogger::emailSent /
 * AppLogger::emailFailed / AppLogger::emailTestFailed (channel: app).
 */
class EmailService
{
    /**
     * Sends an email via the SendGrid v3 HTTP API.
     *
     * Uses the sendgrid/sendgrid PHP SDK. Does not fall back to SMTP on failure —
     * returns an error payload instead so the caller can decide.
     *
     * Side effects: logs via AppLogger::emailSent on success, AppLogger::emailFailed
     * on exception (channel: app).
     *
     * @param  string  $toEmail  Recipient email address.
     * @param  string  $subject  Email subject line.
     * @param  string  $htmlContent  HTML body.
     * @param  string|null  $textContent  Optional plain-text fallback body.
     * @param  string|null  $toName  Optional recipient display name.
     * @return array{success: bool, message: string, to?: string, method?: string, status_code?: int, error?: string}
     */
    public function sendEmailViaSendGridAPI(string $toEmail, string $subject, string $htmlContent, ?string $textContent = null, ?string $toName = null): array
    {
        try {
            $apiKey = config('services.sendgrid.api_key');

            if (empty($apiKey) || $apiKey === 'SENDGRID_API_KEY_PLACEHOLDER') {
                throw new \Exception('SendGrid API Key não configurada');
            }

            $sendgrid = new SendGrid($apiKey);
            $email = new SendGridMail;

            // Configurar remetente
            $email->setFrom(
                config('services.sendgrid.from.email', config('mail.from.address')),
                config('services.sendgrid.from.name', config('mail.from.name'))
            );

            // Configurar destinatário
            $email->addTo($toEmail, $toName ?? $toEmail);

            // Configurar assunto e conteúdo
            $email->setSubject($subject);
            $email->addContent('text/html', $htmlContent);

            if ($textContent) {
                $email->addContent('text/plain', $textContent);
            }

            // Enviar email
            $response = $sendgrid->send($email);

            AppLogger::emailSent($toEmail, 'unknown', 'sendgrid');

            return [
                'success' => true,
                'message' => 'Email enviado com sucesso via SendGrid API',
                'to' => $toEmail,
                'method' => 'SendGrid API',
                'status_code' => $response->statusCode(),
            ];

        } catch (\Exception $e) {
            AppLogger::emailFailed($toEmail, 'unknown', $e);

            return [
                'success' => false,
                'message' => 'Erro ao enviar email via SendGrid API: '.$e->getMessage(),
                'error' => $e->getMessage(),
                'method' => 'SendGrid API',
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
                    <span class='badge'>Laravel Mail Nativo + SendGrid</span>
                </div>
                <div class='content'>
                    <h2>✅ Email Laravel Mail Funcionando!</h2>

                    <p>Olá <strong>{$data['name']}</strong>,</p>

                    <div class='success'>
                        <strong>🎉 SUCESSO!</strong><br>
                        Laravel Mail + SendGrid configurado e funcionando perfeitamente!
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
                            <tr><th>Provider</th><td>SendGrid SMTP</td></tr>
                        </table>
                    </div>

                    <p><strong>🔧 Melhorias Implementadas:</strong></p>
                    <ul>
                        <li>✅ Removido PHPMailer (conflito resolvido)</li>
                        <li>✅ Usando Laravel Mail nativo</li>
                        <li>✅ Configuração SendGrid otimizada</li>
                        <li>✅ Timeout e verificação SSL configurados</li>
                        <li>✅ Logs detalhados para debug</li>
                    </ul>

                    <p>O sistema de email está agora <strong>100% funcional</strong> e otimizado para produção!</p>

                    <p><em>Este é um email automático de teste. Não é necessário responder.</em></p>
                </div>
                <div class='footer'>
                    <p><strong>Link Charts</strong> - Sistema de Encurtamento de URLs<br>
                    <small>Desenvolvido com ❤️ usando Laravel + SendGrid</small></p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Template HTML para email de teste SendGrid API
     */
    private function getSendGridTestEmailTemplate($data)
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Teste SendGrid API - Link Charts</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #4caf50, #66bb6a); color: white; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                .success { background: #4caf50; color: white; padding: 15px; border-radius: 4px; margin: 20px 0; text-align: center; }
                .info { background: #e8f5e8; padding: 20px; border-left: 4px solid #4caf50; margin: 20px 0; }
                .config-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                .config-table th, .config-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
                .config-table th { background: #f5f5f5; font-weight: bold; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
                .badge { background: #4caf50; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
                .api-badge { background: #2196f3; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; margin-left: 10px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🚀 Link Charts</h1>
                    <p>Sistema de Encurtamento de URLs</p>
                    <span class='badge'>SendGrid API</span>
                    <span class='api-badge'>HTTPS - Porta 443</span>
                </div>
                <div class='content'>
                    <h2>✅ SendGrid API Funcionando!</h2>

                    <p>Olá <strong>{$data['name']}</strong>,</p>

                    <div class='success'>
                        <strong>🎉 SUCESSO!</strong><br>
                        SendGrid API configurada e funcionando perfeitamente!<br>
                        <small>Problema da porta 587 resolvido!</small>
                    </div>

                    <div class='info'>
                        <strong>📋 Detalhes da Configuração:</strong><br>
                        <table class='config-table'>
                            <tr><th>Data/Hora</th><td>{$data['timestamp']}</td></tr>
                            <tr><th>Método</th><td>{$data['method']}</td></tr>
                            <tr><th>Protocolo</th><td>HTTPS (Porta 443)</td></tr>
                            <tr><th>Status API</th><td>{$data['api_status']}</td></tr>
                            <tr><th>Ambiente</th><td>{$data['environment']}</td></tr>
                            <tr><th>SMTP Bypass</th><td>✅ Sim - Não usa porta 587</td></tr>
                            <tr><th>Provider</th><td>SendGrid API v3</td></tr>
                        </table>
                    </div>

                    <p><strong>🔧 Solução Implementada:</strong></p>
                    <ul>
                        <li>✅ Migrado de SMTP para SendGrid API</li>
                        <li>✅ Bypass completo da porta 587 bloqueada</li>
                        <li>✅ Usa HTTPS (porta 443) - sempre liberada</li>
                        <li>✅ Melhor performance que SMTP</li>
                        <li>✅ Logs detalhados para monitoramento</li>
                        <li>✅ Compatível com DigitalOcean</li>
                    </ul>

                    <p><strong>🚀 Vantagens da API:</strong></p>
                    <ul>
                        <li>🔒 Mais seguro (HTTPS nativo)</li>
                        <li>⚡ Mais rápido que SMTP</li>
                        <li>📊 Métricas avançadas disponíveis</li>
                        <li>🛡️ Não afetado por bloqueios de porta</li>
                    </ul>

                    <p>O sistema de email está agora <strong>100% funcional</strong> usando SendGrid API!</p>

                    <p><em>Este é um email automático de teste. Não é necessário responder.</em></p>
                </div>
                <div class='footer'>
                    <p><strong>Link Charts</strong> - Sistema de Encurtamento de URLs<br>
                    <small>Desenvolvido com ❤️ usando Laravel + SendGrid API</small></p>
                </div>
            </div>
        </body>
        </html>";
    }
}
