<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Mail\Message;

class EmailService
{
    /**
     * Enviar email de teste usando Laravel Mail nativo
     */
    public function sendTestEmail($toEmail, $toName = null)
    {
        try {
            $data = [
                'name' => $toName ?? $toEmail,
                'email' => $toEmail,
                'timestamp' => now()->format('d/m/Y H:i:s'),
                'environment' => config('app.env'),
                'smtp_host' => config('mail.mailers.smtp.host'),
                'smtp_port' => config('mail.mailers.smtp.port'),
                'encryption' => config('mail.mailers.smtp.port') == 465 ? 'SSL' : 'TLS'
            ];

            Mail::send([], [], function (Message $message) use ($toEmail, $toName, $data) {
                $message->to($toEmail, $toName ?? $toEmail)
                    ->subject('Teste de Email - Link Charts')
                    ->html($this->getTestEmailTemplate($data));
            });

            Log::info('Email de teste enviado com sucesso via Laravel Mail', [
                'to' => $toEmail,
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host')
            ]);

            return [
                'success' => true,
                'message' => 'Email enviado com sucesso via Laravel Mail',
                'to' => $toEmail,
                'mailer' => config('mail.default')
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao enviar email de teste via Laravel Mail', [
                'to' => $toEmail,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'mailer_config' => [
                    'default' => config('mail.default'),
                    'host' => config('mail.mailers.smtp.host'),
                    'port' => config('mail.mailers.smtp.port'),
                    'username' => config('mail.mailers.smtp.username'),
                    'from_address' => config('mail.from.address')
                ]
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao enviar email: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'mailer' => config('mail.default')
            ];
        }
    }

    /**
     * Testar configuração do Laravel Mail
     */
    public function testConnection()
    {
        try {
            // Verificar configurações básicas
            $config = $this->getMailConfiguration();

            if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
                return [
                    'success' => false,
                    'message' => 'Configurações de email incompletas',
                    'config' => $config
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
                'config' => $config
            ];

        } catch (\Exception $e) {
            Log::error('Erro na configuração Laravel Mail', [
                'error' => $e->getMessage(),
                'config' => $this->getMailConfiguration()
            ]);

            return [
                'success' => false,
                'message' => 'Erro na configuração: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'config' => $this->getMailConfiguration()
            ];
        }
    }

    /**
     * Obter configurações de email atuais
     */
    public function getMailConfiguration()
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
            'verify_peer' => config('mail.mailers.smtp.verify_peer', 'padrão')
        ];
    }

    /**
     * Enviar email personalizado
     */
    public function sendCustomEmail($toEmail, $subject, $htmlContent, $textContent = null)
    {
        try {
            Mail::send([], [], function (Message $message) use ($toEmail, $subject, $htmlContent, $textContent) {
                $message->to($toEmail)
                    ->subject($subject)
                    ->html($htmlContent);

                if ($textContent) {
                    $message->text($textContent);
                }
            });

            Log::info('Email personalizado enviado com sucesso', [
                'to' => $toEmail,
                'subject' => $subject
            ]);

            return [
                'success' => true,
                'message' => 'Email personalizado enviado com sucesso',
                'to' => $toEmail
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao enviar email personalizado', [
                'to' => $toEmail,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao enviar email: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
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
}
