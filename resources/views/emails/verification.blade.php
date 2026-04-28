<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $app_name }} — Verificação de email</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #1e293b;
            background-color: #f8fafc;
        }
        .wrapper {
            max-width: 600px;
            margin: 0 auto;
            padding: 32px 16px;
        }
        .card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .header {
            background-color: #3b82f6;
            padding: 32px 40px 28px;
            text-align: center;
        }
        .header-logo {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.3px;
        }
        .header-subtitle {
            color: rgba(255, 255, 255, 0.85);
            font-size: 14px;
            margin-top: 4px;
        }
        .body {
            padding: 36px 40px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 12px;
        }
        .text {
            font-size: 15px;
            color: #475569;
            margin-bottom: 28px;
        }
        .btn-wrap {
            text-align: center;
            margin: 32px 0;
        }
        .btn {
            display: inline-block;
            background-color: #3b82f6;
            color: #ffffff !important;
            padding: 14px 32px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
        }
        .details-box {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 16px 20px;
            margin: 24px 0;
            font-size: 14px;
            color: #0c4a6e;
        }
        .details-box strong {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #0369a1;
        }
        .details-row {
            margin-bottom: 4px;
        }
        .security-box {
            background-color: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 16px 20px;
            margin: 24px 0;
            font-size: 14px;
            color: #78350f;
        }
        .security-box strong {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #92400e;
        }
        .security-box ul {
            margin: 0;
            padding-left: 18px;
        }
        .security-box li {
            margin-bottom: 4px;
        }
        .fallback {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 20px;
            margin: 24px 0;
        }
        .fallback p {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 8px;
        }
        .fallback a {
            color: #3b82f6;
            font-size: 13px;
            word-break: break-all;
        }
        .ignore-note {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }
        .footer {
            padding: 20px 40px;
            border-top: 1px solid #f1f5f9;
            text-align: center;
        }
        .footer p {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">

            <div class="header">
                <div class="header-logo">🔗 {{ $app_name }}</div>
                <div class="header-subtitle">Verificação de email</div>
            </div>

            <div class="body">
                <p class="greeting">Olá, {{ $user_name }}!</p>

                <p class="text">
                    Obrigado por se cadastrar no <strong>{{ $app_name }}</strong>!
                    Para ativar sua conta, confirme seu endereço de email clicando no botão abaixo.
                </p>

                <div class="btn-wrap">
                    <a href="{{ $verification_url }}" class="btn">Verificar meu email</a>
                </div>

                <div class="details-box">
                    <strong>Detalhes da verificação</strong>
                    <div class="details-row">Email: <strong>{{ $user_email }}</strong></div>
                    <div class="details-row">Válido até: <strong>{{ $expires_at }}</strong></div>
                </div>

                <div class="security-box">
                    <strong>Importante</strong>
                    <ul>
                        <li>Este link expira em 24 horas.</li>
                        <li>Use apenas se você criou esta conta.</li>
                        <li>Não compartilhe com ninguém.</li>
                    </ul>
                </div>

                <div class="fallback">
                    <p>Não consegue clicar no botão? Copie e cole no navegador:</p>
                    <a href="{{ $verification_url }}">{{ $verification_url }}</a>
                </div>

                <p class="ignore-note">
                    Se você não criou esta conta, ignore este email — nenhuma ação é necessária.
                </p>
            </div>

            <div class="footer">
                <p>
                    © {{ date('Y') }} {{ $app_name }}. Todos os direitos reservados.<br>
                    Este e-mail foi gerado automaticamente. Não é necessário responder.
                </p>
            </div>

        </div>
    </div>
</body>
</html>
