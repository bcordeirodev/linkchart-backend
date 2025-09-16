<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperação de Senha - {{ config('app.name') }}</title>
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
            margin-bottom: 20px;
            color: #1e293b;
        }
        .message {
            font-size: 16px;
            margin-bottom: 25px;
            color: #475569;
        }
        .button-container {
            text-align: center;
            margin: 30px 0;
        }
        .reset-button {
            display: inline-block;
            background-color: #3b82f6;
            color: #ffffff;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            transition: background-color 0.3s ease;
        }
        .reset-button:hover {
            background-color: #2563eb;
        }
        .alternative-link {
            margin-top: 20px;
            padding: 15px;
            background-color: #f1f5f9;
            border-radius: 6px;
            border-left: 4px solid #3b82f6;
        }
        .alternative-link p {
            margin: 0;
            font-size: 14px;
            color: #475569;
        }
        .alternative-link a {
            color: #3b82f6;
            word-break: break-all;
        }
        .security-info {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
        }
        .security-info h4 {
            margin: 0 0 10px 0;
            color: #92400e;
            font-size: 16px;
        }
        .security-info p {
            margin: 0;
            color: #92400e;
            font-size: 14px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #64748b;
            font-size: 14px;
        }
        .footer a {
            color: #3b82f6;
            text-decoration: none;
        }
        .expiry-info {
            background-color: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 6px;
            padding: 12px;
            margin: 15px 0;
            text-align: center;
        }
        .expiry-info p {
            margin: 0;
            color: #0c4a6e;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">🔗 {{ config('app.name') }}</div>
            <div class="subtitle">Plataforma de Encurtamento de URLs</div>
        </div>

        <div class="content">
            <div class="greeting">
                Olá, {{ $user->name }}! 👋
            </div>

            <div class="message">
                Recebemos uma solicitação para redefinir a senha da sua conta no <strong>{{ config('app.name') }}</strong>.
            </div>

            <div class="message">
                Para criar uma nova senha, clique no botão abaixo:
            </div>

            <div class="button-container">
                <a href="{{ $resetUrl }}" class="reset-button">
                    🔐 Redefinir Minha Senha
                </a>
            </div>

            <div class="expiry-info">
                <p>⏰ Este link expira em 24 horas por segurança</p>
            </div>

            <div class="alternative-link">
                <p><strong>Não consegue clicar no botão?</strong></p>
                <p>Copie e cole este link no seu navegador:</p>
                <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
            </div>

            <div class="security-info">
                <h4>🛡️ Informações de Segurança</h4>
                <p>
                    <strong>Não solicitou esta alteração?</strong><br>
                    Se você não solicitou a redefinição de senha, ignore este e-mail.
                    Sua senha permanecerá inalterada e sua conta estará segura.
                </p>
            </div>

            <div class="message">
                <strong>Dicas de Segurança:</strong>
                <ul style="color: #475569; margin-left: 20px;">
                    <li>Use uma senha forte com pelo menos 8 caracteres</li>
                    <li>Combine letras maiúsculas, minúsculas, números e símbolos</li>
                    <li>Não compartilhe sua senha com ninguém</li>
                    <li>Use senhas diferentes para cada serviço</li>
                </ul>
            </div>
        </div>

        <div class="footer">
            <p>
                Este e-mail foi enviado automaticamente pelo sistema {{ config('app.name') }}.<br>
                Para suporte, entre em contato: <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>
            </p>
            <p style="margin-top: 15px; font-size: 12px; color: #94a3b8;">
                © {{ date('Y') }} {{ config('app.name') }}. Todos os direitos reservados.<br>
                Plataforma profissional de encurtamento e análise de URLs.
            </p>
        </div>
    </div>
</body>
</html>
