<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Illuminate\Support\Facades\Log;

class EmailService
{
    private PHPMailer $mail;

    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        $this->configureSMTP();
    }

    /**
     * Configurar SMTP
     */
    private function configureSMTP()
    {
        try {
            // Configurações do servidor
            $this->mail->isSMTP();
            $this->mail->Host = config('mail.mailers.smtp.host', 'smtp.gmail.com');
            $this->mail->SMTPAuth = true;
            $this->mail->Username = config('mail.mailers.smtp.username');
            $this->mail->Password = config('mail.mailers.smtp.password');

            // Configurações de porta e encryption
            $port = config('mail.mailers.smtp.port', 465);
            $this->mail->Port = $port;

            if ($port == 465) {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            // Configurações de timeout
            $this->mail->Timeout = 30;
            $this->mail->SMTPKeepAlive = false;

            // Debug apenas em desenvolvimento
            if (config('app.debug') && config('app.env') === 'local') {
                $this->mail->SMTPDebug = SMTP::DEBUG_SERVER;
                $this->mail->Debugoutput = function($str, $level) {
                    Log::info("SMTP Debug: " . trim($str));
                };
            }

            // Configurações de remetente padrão
            $this->mail->setFrom(
                config('mail.from.address'),
                config('mail.from.name')
            );

            // Configurações adicionais
            $this->mail->isHTML(true);
            $this->mail->CharSet = 'UTF-8';

        } catch (Exception $e) {
            Log::error('Erro na configuração SMTP: ' . $e->getMessage(), [
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'username' => config('mail.mailers.smtp.username')
            ]);
            throw $e;
        }
    }

    /**
     * Enviar email de teste
     */
    public function sendTestEmail($toEmail, $toName = null)
    {
        try {
            // Limpar destinatários anteriores
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();

            // Configurar destinatário
            $this->mail->addAddress($toEmail, $toName ?? $toEmail);

            // Configurar assunto e corpo
            $this->mail->Subject = 'Teste de Email - Link Charts';

            // Corpo HTML
            $htmlBody = $this->getTestEmailTemplate($toName ?? $toEmail);
            $this->mail->Body = $htmlBody;

            // Corpo texto alternativo
            $this->mail->AltBody = $this->getTestEmailTextTemplate($toName ?? $toEmail);

            // Enviar
            $result = $this->mail->send();

            Log::info('Email de teste enviado com sucesso', [
                'to' => $toEmail,
                'subject' => $this->mail->Subject
            ]);

            return [
                'success' => true,
                'message' => 'Email enviado com sucesso',
                'to' => $toEmail
            ];

        } catch (Exception $e) {
            Log::error('Erro ao enviar email de teste', [
                'to' => $toEmail,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'message' => 'Erro ao enviar email: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Testar conectividade SMTP
     */
    public function testConnection()
    {
        try {
            // Tentar conectar ao servidor SMTP
            $result = $this->mail->smtpConnect();

            if ($result) {
                $this->mail->smtpClose();
                return [
                    'success' => true,
                    'message' => 'Conexão SMTP estabelecida com sucesso'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Falha na conexão SMTP'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro na conexão SMTP: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Template HTML para email de teste
     */
    private function getTestEmailTemplate($name)
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Teste de Email - Link Charts</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #1976d2; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
                .success { background: #4caf50; color: white; padding: 15px; border-radius: 4px; margin: 20px 0; }
                .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #1976d2; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🚀 Link Charts</h1>
                    <p>Sistema de Encurtamento de URLs</p>
                </div>
                <div class='content'>
                    <h2>✅ Teste de Email Realizado com Sucesso!</h2>

                    <p>Olá <strong>{$name}</strong>,</p>

                    <div class='success'>
                        <strong>✅ Configuração de Email Funcionando!</strong><br>
                        Este email confirma que o sistema de envio está operacional.
                    </div>

                    <div class='info'>
                        <strong>📋 Detalhes do Teste:</strong><br>
                        • <strong>Data/Hora:</strong> " . date('d/m/Y H:i:s') . "<br>
                        • <strong>Servidor SMTP:</strong> " . config('mail.mailers.smtp.host') . "<br>
                        • <strong>Porta:</strong> " . config('mail.mailers.smtp.port') . "<br>
                        • <strong>Encryption:</strong> " . (config('mail.mailers.smtp.port') == 465 ? 'SSL' : 'TLS') . "<br>
                        • <strong>Ambiente:</strong> " . config('app.env') . "
                    </div>

                    <p>O sistema de email do <strong>Link Charts</strong> está configurado corretamente e pronto para uso!</p>

                    <p>Este é um email automático de teste. Não é necessário responder.</p>
                </div>
                <div class='footer'>
                    <p>Link Charts - Sistema de Encurtamento de URLs<br>
                    <small>Desenvolvido com ❤️ usando Laravel + PHPMailer</small></p>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Template texto para email de teste
     */
    private function getTestEmailTextTemplate($name)
    {
        return "
LINK CHARTS - TESTE DE EMAIL
============================

Olá {$name},

✅ CONFIGURAÇÃO DE EMAIL FUNCIONANDO!
Este email confirma que o sistema de envio está operacional.

DETALHES DO TESTE:
• Data/Hora: " . date('d/m/Y H:i:s') . "
• Servidor SMTP: " . config('mail.mailers.smtp.host') . "
• Porta: " . config('mail.mailers.smtp.port') . "
• Encryption: " . (config('mail.mailers.smtp.port') == 465 ? 'SSL' : 'TLS') . "
• Ambiente: " . config('app.env') . "

O sistema de email do Link Charts está configurado corretamente e pronto para uso!

Este é um email automático de teste. Não é necessário responder.

---
Link Charts - Sistema de Encurtamento de URLs
Desenvolvido com Laravel + PHPMailer
        ";
    }
}
