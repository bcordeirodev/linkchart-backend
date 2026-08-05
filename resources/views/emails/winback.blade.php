<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seu link ainda não teve cliques — Link Charts</title>
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
                                {{ count($link_labels) === 1 ? 'Um link seu completou duas semanas sem cliques' : 'Alguns links seus completaram duas semanas sem cliques' }}
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 0 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;border-radius:10px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;">{{ count($link_labels) === 1 ? 'Link parado' : 'Links parados' }}</div>
                                        @foreach ($link_labels as $label)
                                            <div style="margin-top:6px;font-size:15px;font-weight:600;color:#111827;">{{ $label }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px 0 32px;">
                            <p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;color:#374151;">
                                Link sem cliques quase sempre é link que ninguém viu. Três lugares que costumam
                                destravar isso em minutos:
                            </p>
                            <p style="margin:0 0 10px 0;font-size:14px;line-height:1.6;color:#374151;">
                                <strong style="color:#111827;">1. Status do WhatsApp.</strong>
                                Publique o link no seu status com uma frase curta dizendo o que a pessoa ganha ao clicar.
                            </p>
                            <p style="margin:0 0 10px 0;font-size:14px;line-height:1.6;color:#374151;">
                                <strong style="color:#111827;">2. Link na bio do Instagram.</strong>
                                Troque o link da bio pelo seu e chame para clicar nos stories do mesmo dia.
                            </p>
                            <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
                                <strong style="color:#111827;">3. Grupos e comunidades do seu nicho.</strong>
                                Compartilhe onde o assunto já é discutido — dois ou três grupos certos rendem mais que uma
                                postagem genérica.
                            </p>
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
                                Você recebe este aviso porque tem links ativos no Link Charts — é enviado uma única vez por link.
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
