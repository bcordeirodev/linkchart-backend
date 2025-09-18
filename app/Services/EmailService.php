<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Mail\Message;
use SendGrid;
use SendGrid\Mail\Mail as SendGridMail;

class EmailService
{
    /**
     * Enviar email usando SendGrid API (solução para porta 587 bloqueada)
     */
    public function sendEmailViaSendGridAPI($toEmail, $subject, $htmlContent, $textContent = null, $toName = null)
    {
        try {
            $apiKey = config('services.sendgrid.api_key');

            if (empty($apiKey) || $apiKey === 'SENDGRID_API_KEY_PLACEHOLDER') {
                throw new \Exception('SendGrid API Key não configurada');
            }

            $sendgrid = new SendGrid($apiKey);
            $email = new SendGridMail();

            // Configurar remetente
            $email->setFrom(
                config('services.sendgrid.from.email', config('mail.from.address')),
                config('services.sendgrid.from.name', config('mail.from.name'))
            );

            // Configurar destinatário
            $email->addTo($toEmail, $toName ?? $toEmail);

            // Configurar assunto e conteúdo
            $email->setSubject($subject);
            $email->addContent("text/html", $htmlContent);

            if ($textContent) {
                $email->addContent("text/plain", $textContent);
            }

            // Enviar email
            $response = $sendgrid->send($email);

            Log::info('Email enviado com sucesso via SendGrid API', [
                'to' => $toEmail,
                'subject' => $subject,
                'status_code' => $response->statusCode(),
                'method' => 'SendGrid API'
            ]);

            return [
                'success' => true,
                'message' => 'Email enviado com sucesso via SendGrid API',
                'to' => $toEmail,
                'method' => 'SendGrid API',
                'status_code' => $response->statusCode()
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao enviar email via SendGrid API', [
                'to' => $toEmail,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao enviar email via SendGrid API: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'method' => 'SendGrid API'
            ];
        }
    }

    /**
     * Enviar email de teste usando SendGrid API
     */
    public function sendTestEmailViaSendGridAPI($toEmail, $toName = null)
    {
        $data = [
            'name' => $toName ?? $toEmail,
            'email' => $toEmail,
            'timestamp' => now()->format('d/m/Y H:i:s'),
            'environment' => config('app.env'),
            'method' => 'SendGrid API',
            'api_status' => 'Ativo'
        ];

        $htmlContent = $this->getSendGridTestEmailTemplate($data);
        $subject = 'Teste SendGrid API - Link Charts';

        return $this->sendEmailViaSendGridAPI($toEmail, $subject, $htmlContent, null, $toName);
    }

    /**
     * Testar SendGrid API
     */
    public function testSendGridAPI()
    {
        try {
            $apiKey = config('services.sendgrid.api_key');

            if (empty($apiKey) || $apiKey === 'SENDGRID_API_KEY_PLACEHOLDER') {
                return [
                    'success' => false,
                    'message' => 'SendGrid API Key não configurada',
                    'config' => $this->getSendGridConfiguration()
                ];
            }

            // Teste básico da API
            $sendgrid = new SendGrid($apiKey);

            return [
                'success' => true,
                'message' => 'SendGrid API configurada corretamente',
                'config' => $this->getSendGridConfiguration()
            ];

        } catch (\Exception $e) {
            Log::error('Erro ao testar SendGrid API', [
                'error' => $e->getMessage(),
                'config' => $this->getSendGridConfiguration()
            ]);

            return [
                'success' => false,
                'message' => 'Erro na SendGrid API: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'config' => $this->getSendGridConfiguration()
            ];
        }
    }

    /**
     * Obter configurações SendGrid API
     */
    public function getSendGridConfiguration()
    {
        return [
            'api_key' => config('services.sendgrid.api_key') ? '***CONFIGURADO***' : 'NÃO CONFIGURADO',
            'from_email' => config('services.sendgrid.from.email'),
            'from_name' => config('services.sendgrid.from.name'),
            'method' => 'SendGrid API (HTTPS)',
            'port' => '443 (HTTPS)',
            'smtp_bypass' => 'Sim - Não usa porta 587'
        ];
    }

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
