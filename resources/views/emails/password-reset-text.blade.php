Recuperação de Senha - {{ config('app.name') }}

Olá, {{ $user->name }}!

Recebemos uma solicitação para redefinir a senha da sua conta no {{ config('app.name') }}.

Para criar uma nova senha, acesse o link abaixo:
{{ $resetUrl }}

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
Este e-mail foi enviado automaticamente pelo sistema {{ config('app.name') }}.
Para suporte, entre em contato: {{ config('mail.from.address') }}

© {{ date('Y') }} {{ config('app.name') }}. Todos os direitos reservados.
Plataforma profissional de encurtamento e análise de URLs.
