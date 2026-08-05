<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumo semanal — Link Charts</title>
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
                                Resumo dos seus links de {{ $period_label }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:24px 32px 8px 32px;">
                            <div style="font-size:44px;font-weight:700;line-height:1;color:#111827;">{{ $total }}</div>
                            <div style="margin-top:6px;font-size:14px;color:#374151;">{{ $total === 1 ? 'clique na última semana' : 'cliques na última semana' }}</div>
                            @if ($first_week)
                                <div style="display:inline-block;margin-top:12px;padding:6px 12px;border-radius:999px;background-color:#ecfdf5;color:#047857;font-size:13px;font-weight:600;">
                                    🎉 Primeira semana com cliques!
                                </div>
                            @elseif ($variation_label !== null)
                                <div style="display:inline-block;margin-top:12px;padding:6px 12px;border-radius:999px;background-color:{{ str_starts_with($variation_label, '-') ? '#fef2f2' : '#ecfdf5' }};color:{{ str_starts_with($variation_label, '-') ? '#b91c1c' : '#047857' }};font-size:13px;font-weight:600;">
                                    {{ $variation_label }} vs semana anterior
                                </div>
                            @endif
                        </td>
                    </tr>
                    @if ($top_link_label !== null)
                        <tr>
                            <td style="padding:24px 32px 0 32px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:10px;">
                                    <tr>
                                        <td style="padding:16px 20px;">
                                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;">Top link da semana</div>
                                            <div style="margin-top:4px;font-size:15px;font-weight:600;color:#111827;">{{ $top_link_label }}</div>
                                            <div style="margin-top:2px;font-size:13px;color:#374151;">{{ $top_link_clicks === 1 ? '1 clique' : $top_link_clicks.' cliques' }}</div>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td align="center" style="padding:28px 32px 32px 32px;">
                            <a href="{{ $stats_url }}"
                               style="display:inline-block;background-color:#2563eb;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:13px 28px;border-radius:10px;">
                                Ver estatísticas completas
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px 32px;border-top:1px solid #e5e7eb;">
                            <p style="margin:24px 0 0 0;font-size:13px;line-height:1.6;color:#6b7280;">
                                Você recebe este resumo semanal porque tem links ativos no Link Charts.
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
