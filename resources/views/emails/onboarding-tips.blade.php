<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>3 recursos que quase ninguém usa — Link Charts</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding:32px 32px 8px 32px;">
                            <h1 style="margin:0;font-size:22px;line-height:1.3;color:#111827;">Olá, {{ $user_name }}!</h1>
                            <p style="margin:8px 0 0 0;font-size:13px;line-height:1.5;color:#6b7280;">
                                Três recursos do Link Charts que passam despercebidos — e são grátis
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <div style="font-size:15px;font-weight:600;color:#111827;">1. Endereço personalizado</div>
                                        <div style="margin-top:4px;font-size:14px;line-height:1.6;color:#374151;">
                                            Seus links podem sair como <strong>seunome.linkcharts.com.br/promo</strong> em vez do
                                            domínio padrão. Dá para registrar até 3 endereços sem pagar nada.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 32px 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <div style="font-size:15px;font-weight:600;color:#111827;">2. Página bio</div>
                                        <div style="margin-top:4px;font-size:14px;line-height:1.6;color:#374151;">
                                            Uma página só sua reunindo todos os links, para colar na bio do Instagram ou do
                                            TikTok — com as mesmas estatísticas de clique dos links normais.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 32px 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <div style="font-size:15px;font-weight:600;color:#111827;">3. Gerador de UTM grátis</div>
                                        <div style="margin-top:4px;font-size:14px;line-height:1.6;color:#374151;">
                                            Monte parâmetros de campanha sem errar a sintaxe e descubra qual post trouxe cada
                                            clique: <a href="{{ $utm_builder_url }}" style="color:#2563eb;">gerador de UTM</a>.
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:28px 32px 32px 32px;">
                            <a href="{{ $links_url }}"
                               style="display:inline-block;background-color:#2563eb;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:13px 28px;border-radius:10px;">
                                Ver meus links
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px 32px;border-top:1px solid #e5e7eb;">
                            <p style="margin:24px 0 0 0;font-size:13px;line-height:1.6;color:#6b7280;">
                                Você recebe este e-mail porque criou uma conta no Link Charts há poucos dias — ele é enviado uma única vez.
                                <a href="{{ $unsubscribe_url }}" style="color:#6b7280;text-decoration:underline;">Não quero mais receber</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
