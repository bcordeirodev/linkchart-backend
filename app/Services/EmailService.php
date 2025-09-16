<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;
use Illuminate\Support\Facades\Log;

class EmailService
{
    private $mail;

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
            $this->mail->Host       = config('mail.mailers.smtp.host', 'smtp.gmail.com');
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = config('mail.mailers.smtp.username');
            $this->mail->Password   = config('mail.mailers.smtp.password');

            // Tentar diferentes configurações de porta/encryption
            $port = config('mail.mailers.smtp.port', 587);
            $encryption = config('mail.mailers.smtp.encryption', 'tls');

            if ($port == 465) {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $this->mail->Port = $port;

            // Configurações adicionais para debug
            if (config('app.debug')) {
                $this->mail->SMTPDebug = SMTP::DEBUG_CONNECTION;
                $this->mail->Debugoutput = function($str, $level) {
                    Log::info("SMTP Debug: " . $str);
                };
            }

            // Configurações de timeout
            $this->mail->Timeout = 30;
            $this->mail->SMTPKeepAlive = false;

            // Configurações de remetente padrão
            $this->mail->setFrom(
                config('mail.from.address'),
                config('mail.from.name')
            );

        } catch (Exception $e) {
            Log::error('Erro na configuração SMTP: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Enviar email de recuperação de senha
     */
    public function sendPasswordResetEmail($email, $userName, $resetUrl, $token)
    {
        try {
            // Limpar destinatários anteriores
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();

            // Configurar destinatário
            $this->mail->addAddress($email, $userName);

            // Configurar email
            $this->mail->isHTML(true);
            $this->mail->Subject = '🔐 Recuperação de Senha - ' . config('app.name');

            // Corpo HTML
            $this->mail->Body = $this->getPasswordResetHtmlBody($userName, $resetUrl, $token);

            // Corpo texto alternativo
            $this->mail->AltBody = $this->getPasswordResetTextBody($userName, $resetUrl, $token);

            // Enviar
            $result = $this->mail->send();

            Log::info("Email de recuperação enviado com sucesso para: {$email}");

            return [
                'success' => true,
                'message' => 'Email enviado com sucesso'
            ];

        } catch (Exception $e) {
            Log::error("Erro ao enviar email para {$email}: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Erro ao enviar email: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Corpo HTML do email de recuperação
     */
    private function getPasswordResetHtmlBody($userName, $resetUrl, $token)
    {
        return "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Recuperação de Senha - " . config('app.name') . "</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                    background-color: #f8fafc;
                }
                .container {
                    background-color: #ffffff;
                    border-radius: 12px;
                    padding: 40px;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                    border: 1px solid #e2e8f0;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    border-bottom: 2px solid #3b82f6;
                    padding-bottom: 20px;
                }
                .logo {
                    font-size: 28px;
                    font-weight: bold;
                    color: #3b82f6;
                    margin-bottom: 10px;
                }
                .subtitle {
                    color: #64748b;
                    font-size: 16px;
                }
                .content {
                    margin-bottom: 30px;
                }
                .greeting {
                    font-size: 18px;
                    font-weight: 600;
                    color: #1e293b;
                    margin-bottom: 20px;
                }
                .message {
                    font-size: 16px;
                    color: #475569;
                    margin-bottom: 25px;
                }
                .button-container {
                    text-align: center;
                    margin: 30px 0;
                }
                .reset-button {
                    display: inline-block;
                    background-color: #3b82f6;
                    color: white;
                    padding: 15px 30px;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 600;
                    font-size: 16px;
                    transition: background-color 0.3s;
                }
                .reset-button:hover {
                    background-color: #2563eb;
                }
                .security-info {
                    background-color: #fef3c7;
                    border: 1px solid #f59e0b;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 25px 0;
                }
                .security-title {
                    font-weight: 600;
                    color: #92400e;
                    margin-bottom: 10px;
                }
                .security-list {
                    color: #92400e;
                    margin: 0;
                    padding-left: 20px;
                }
                .tips {
                    background-color: #ecfdf5;
                    border: 1px solid #10b981;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 25px 0;
                }
                .tips-title {
                    font-weight: 600;
                    color: #065f46;
                    margin-bottom: 10px;
                }
                .tips-list {
                    color: #065f46;
                    margin: 0;
                    padding-left: 20px;
                }
                .footer {
                    border-top: 1px solid #e2e8f0;
                    padding-top: 20px;
                    text-align: center;
                    font-size: 14px;
                    color: #64748b;
                }
                .footer-links {
                    margin-top: 15px;
                }
                .footer-link {
                    color: #3b82f6;
                    text-decoration: none;
                    margin: 0 10px;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>" . config('app.name') . "</div>
                    <div class='subtitle'>Plataforma profissional de encurtamento e análise de URLs</div>
                </div>

                <div class='content'>
                    <div class='greeting'>Olá, {$userName}!</div>

                    <div class='message'>
                        Recebemos uma solicitação para redefinir a senha da sua conta no " . config('app.name') . ".
                    </div>

                    <div class='message'>
                        Para criar uma nova senha, clique no botão abaixo:
                    </div>

                    <div class='button-container'>
                        <a href='{$resetUrl}' class='reset-button'>🔐 Redefinir Senha</a>
                    </div>

                    <div class='security-info'>
                        <div class='security-title'>⚠️ IMPORTANTE:</div>
                        <ul class='security-list'>
                            <li>Este link expira em <strong>24 horas</strong> por segurança</li>
                            <li>Se você não solicitou esta alteração, ignore este e-mail</li>
                            <li>Sua senha permanecerá inalterada se você não usar este link</li>
                        </ul>
                    </div>

                    <div class='tips'>
                        <div class='tips-title'>💡 Dicas de Segurança:</div>
                        <ul class='tips-list'>
                            <li>Use uma senha forte com pelo menos 8 caracteres</li>
                            <li>Combine letras maiúsculas, minúsculas, números e símbolos</li>
                            <li>Não compartilhe sua senha com ninguém</li>
                            <li>Use senhas diferentes para cada serviço</li>
                        </ul>
                    </div>
                </div>

                <div class='footer'>
                    <p>Este e-mail foi enviado automaticamente pelo sistema " . config('app.name') . ".</p>
                    <p>Para suporte, entre em contato: " . config('mail.from.address') . "</p>
                    <div class='footer-links'>
                        <p>© " . date('Y') . " " . config('app.name') . ". Todos os direitos reservados.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Corpo texto do email de recuperação
     */
    private function getPasswordResetTextBody($userName, $resetUrl, $token)
    {
        return "
Recuperação de Senha - " . config('app.name') . "

Olá, {$userName}!

Recebemos uma solicitação para redefinir a senha da sua conta no " . config('app.name') . ".

Para criar uma nova senha, acesse o link abaixo:
{$resetUrl}

IMPORTANTE:
- Este link expira em 24 horas por segurança
- Se você não solicitou esta alteração, ignore este e-mail
- Sua senha permanecerá inalterada se você não usar este link

Dicas de Segurança:
- Use uma senha forte com pelo menos 8 caracteres
- Combine letras maiúsculas, minúsculas, números e símbolos
- Não compartilhe sua senha com ninguém
- Use senhas diferentes para cada serviço

---
Este e-mail foi enviado automaticamente pelo sistema " . config('app.name') . ".
Para suporte, entre em contato: " . config('mail.from.address') . "

© " . date('Y') . " " . config('app.name') . ". Todos os direitos reservados.
Plataforma profissional de encurtamento e análise de URLs.
        ";
    }

    /**
     * Testar configuração de email
     */
    public function testConnection()
    {
        try {
            // Testar conexão SMTP
            $this->mail->smtpConnect();
            $this->mail->smtpClose();

            return [
                'success' => true,
                'message' => 'Conexão SMTP estabelecida com sucesso'
            ];

        } catch (Exception $e) {
            Log::error('Erro no teste de conexão SMTP: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Erro na conexão SMTP: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
}
