# Auth

## Propósito

Gerencia o ciclo completo de identidade do usuário: criação de conta, autenticação JWT,
verificação de e-mail, recuperação de senha e atualização de perfil. É o portão de entrada
para todo o resto da aplicação, pois todos os outros domínios exigem um token emitido aqui.

## Feature espelhada no frontend

`frontend-next/src/features/profile/` e estado de autenticação global (Redux `messageSlice`,
`ApiClient` injetando `Authorization: Bearer`).

## Endpoints

| Verb | Path | Controller@action | Middleware (route-specific) | Auth |
|---|---|---|---|---|
| POST | /api/auth/register | `AuthController@register` | `throttle:login` | not required |
| POST | /api/auth/login | `AuthController@login` | `throttle:login` | not required |
| POST | /api/auth/verify-email | `AuthController@verifyEmail` | `throttle:login` | not required |
| POST | /api/auth/forgot-password | `AuthController@forgotPassword` | `throttle:login` | not required |
| POST | /api/auth/reset-password | `AuthController@resetPassword` | `throttle:login` | not required |
| GET | /api/me | `AuthController@me` | — | required (JWT) |
| POST | /api/logout | `AuthController@logout` | — | required (JWT) |
| GET | /api/email-verification-status | `AuthController@checkEmailVerificationStatus` | — | required (JWT) |
| POST | /api/resend-verification-email | `AuthController@resendVerificationEmail` | — | required (JWT) |
| PUT | /api/profile | `AuthController@updateProfile` | `verified` | required (JWT + verified) |
| PUT | /api/change-password | `AuthController@changePassword` | `verified` | required (JWT + verified) |

> Os cinco endpoints de autenticação pública usam o rate limiter `throttle:login`
> (5 tentativas/min por `email` ou IP). Os endpoints protegidos apenas por `api.auth:api`
> **não** passam por esse limiter — ver Pontos de atenção.

## Services e Repositories

- `EmailVerificationService` — emite e valida tokens de verificação de e-mail (TTL 24 h)
  e tokens de reset de senha (TTL 1 h); entrega via API SendGrid.
- `EmailService` — wrapper de baixo nível ao redor do SDK `sendgrid/sendgrid`; chamado
  internamente pelo `EmailVerificationService`.
- `tymon/jwt-auth` (via `JWTAuth` facade) — emite, valida e invalida tokens JWT.

Não há Repository dedicado; o model `User` é acessado diretamente pelo controller
(sem camada de serviço intermediária para CRUD de usuário).

## Jobs disparados

Nenhum job é disparado diretamente pelo `AuthController`. O envio de e-mail ocorre de
forma síncrona via `EmailVerificationService::sendVerificationEmail` no ciclo HTTP do
registro — este é o único ponto de latência visível ao usuário.

## Cache

Nenhum cache gerenciado por este domínio. Os tokens de verificação e reset persistem na
tabela `email_verification_tokens` (modelo `EmailVerificationToken`).

## Pontos de atenção

- **JWT fixado em branch de desenvolvimento**: `tymon/jwt-auth` está fixado em
  `dev-chore/laravel-12` no `composer.json` por incompatibilidade com Laravel 12.
  Não altere a constraint sem verificar a compatibilidade.

- **`POST /api/auth/google` foi removido** no commit `79e3411` (refactor R-09): a rota
  foi excluída e o método `googleLogin` nunca existiu no controller. Para reativar login
  Google, **ambos** precisam ser adicionados: método no controller e rota no `routes/api.php`.

- **`resendVerificationEmail` e `checkEmailVerificationStatus` não têm rate limit**:
  esses dois endpoints ficam no grupo `api.auth:api` puro, fora do grupo `throttle:login`.
  O `resendVerificationEmail` envia e-mail em cada chamada — gap de segurança documentado
  na auditoria (§14). Um throttle dedicado deve ser adicionado em follow-up.

- **Janelas de expiração de token**: 24 h para verificação de e-mail, 1 h para reset de
  senha — definidas em `EmailVerificationService` e armazenadas em `expires_at` na tabela
  `email_verification_tokens`.

- **Transporte de e-mail**: SendGrid via pacote `sendgrid/sendgrid` (v8). Configuração em
  `config/services.php` (`sendgrid.key`). Se o e-mail não chegar, verificar `LOG_CHANNEL=app`
  e o canal `auth` em `storage/logs/auth-YYYY-MM-DD.log`.

- **Respostas não passam pelo `NormalizeApiResponse`**: o middleware `NormalizeApiResponse`
  é aplicado apenas ao grupo `api` (links e analytics). As respostas de autenticação têm
  shape própria (ver PHPDoc de cada action).
