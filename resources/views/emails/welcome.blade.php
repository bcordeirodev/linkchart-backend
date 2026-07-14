<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bem-vindo ao Link Charts</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding:32px 32px 8px 32px;">
                            <h1 style="margin:0;font-size:22px;line-height:1.3;color:#111827;">Olá, {{ $user_name }}!</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 0 32px;">
                            <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#374151;">
                                Sua conta no <strong>Link Charts</strong> está pronta. Aqui você encurta links e acompanha
                                cada clique — de onde veio, em que dispositivo e se foi gente ou robô.
                            </p>
                            <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#374151;">
                                Já deixamos um <strong>link de demonstração</strong> na sua conta, com dados reais de exemplo.
                                Abra o analytics dele para ver o que a plataforma mostra antes mesmo de criar o seu primeiro link.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 32px 32px 32px;">
                            <a href="{{ $links_url }}"
                               style="display:inline-block;background-color:#2563eb;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:13px 28px;border-radius:10px;">
                                Ver meus links
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px 32px;border-top:1px solid #e5e7eb;">
                            <p style="margin:24px 0 0 0;font-size:13px;line-height:1.6;color:#6b7280;">
                                Você recebeu este e-mail porque criou uma conta no Link Charts.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
