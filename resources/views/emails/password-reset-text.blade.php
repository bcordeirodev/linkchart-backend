{{ $app_name }} — Recuperação de senha

Olá, {{ $user_name }}!

Recebemos uma solicitação para redefinir a senha da sua conta no {{ $app_name }}.

Para criar uma nova senha, acesse:
{{ $reset_url }}

Detalhes:
- Email: {{ $user_email }}
- Válido até: {{ $expires_at }}

Importante:
- Este link expira em 1 hora.
- Use apenas se você solicitou a redefinição.
- Após o uso, o link é invalidado automaticamente.
- Não compartilhe com ninguém.

Se você não solicitou esta redefinição, ignore este email — sua senha permanece inalterada.

{{ $app_name }}
