<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seus links renderam enquanto você esteve fora — Link Charts</title>
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
                                Seus links continuaram trabalhando enquanto você esteve fora
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:24px 32px 8px 32px;">
                            <div style="font-size:44px;font-weight:700;line-height:1;color:#111827;">{{ $total }}</div>
                            <div style="margin-top:6px;font-size:14px;color:#374151;">cliques nos últimos {{ $window_days }} dias</div>
                        </td>
                    </tr>
                    @if ($top_link_label !== null)
                        <tr>
                            <td style="padding:24px 32px 0 32px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:10px;">
                                    <tr>
                                        <td style="padding:16px 20px;">
                                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;">Link que mais rendeu</div>
                                            <div style="margin-top:4px;font-size:15px;font-weight:600;color:#111827;">{{ $top_link_label }}</div>
                                            <div style="margin-top:2px;font-size:14px;color:#374151;">{{ $top_link_clicks_label }} no período</div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:20px 32px 0 32px;">
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
                                Cada um desses cliques deixou rastro: de onde a pessoa veio, em que horário e
                                por qual rede. É esse mapa que diz onde vale divulgar da próxima vez.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:28px 32px 8px 32px;">
                            <a href="{{ $stats_url }}"
                               style="display:inline-block;background-color:#2563eb;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:13px 28px;border-radius:10px;">
                                Ver estatísticas
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 32px 32px 32px;">
                            <a href="{{ $links_url }}" style="font-size:14px;color:#2563eb;text-decoration:underline;">Ver todos os links</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px 32px;border-top:1px solid #e5e7eb;">
                            <p style="margin:24px 0 0 0;font-size:13px;line-height:1.6;color:#6b7280;">
                                Você recebe este aviso porque tem links ativos no Link Charts — ele é enviado no máximo uma vez a cada {{ $cooldown_days }} dias.
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
