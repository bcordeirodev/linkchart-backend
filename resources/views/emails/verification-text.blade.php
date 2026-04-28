{{ $app_name }} — Verificação de email

Olá, {{ $user_name }}!

Obrigado por se cadastrar no {{ $app_name }}! Para ativar sua conta, confirme seu email acessando:
{{ $verification_url }}

Detalhes:
- Email: {{ $user_email }}
- Válido até: {{ $expires_at }}

Importante:
- Este link expira em 24 horas.
- Use apenas se você criou esta conta.
- Não compartilhe com ninguém.

Se você não criou esta conta, ignore este email — nenhuma ação é necessária.

{{ $app_name }}
