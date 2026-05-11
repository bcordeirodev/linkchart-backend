# Authentication and Email Verification Flow

This diagram covers the full authentication lifecycle: user registration with email verification, password-based login producing a JWT token, authenticated API calls, and the middleware guard that protects verified-only routes.

```mermaid
sequenceDiagram
  autonumber
  participant FE as Frontend
  participant API as Laravel<br/>(AuthController)
  participant JWT as tymon/jwt-auth
  participant DB as Postgres
  participant Mail as SendGrid

  Note over FE,Mail: 1) Registration + email verification
  FE->>API: POST /api/auth/register {email, password, ...}
  API->>DB: create user (email_verified_at = NULL)
  API->>DB: create EmailVerificationToken (24h window)
  API->>Mail: send verification email (EmailVerificationService)
  API-->>FE: 201 {data: user, message: "verify your email"}

  FE->>API: POST /api/auth/verify-email {token}
  API->>DB: find token, mark email_verified_at = now()
  API-->>FE: 200 {message}

  Note over FE,API: 2) Login → JWT token
  FE->>API: POST /api/auth/login {email, password}
  API->>DB: find user by email + verify password
  DB-->>API: User
  API->>JWT: JWTAuth::fromUser($user)
  JWT-->>API: token
  API-->>FE: 200 {data: {token, user}}

  Note over FE: Store token in localStorage<br/>ApiClient sends Authorization: Bearer

  Note over FE,API: 3) Authenticated calls
  FE->>API: GET /api/me  (Authorization: Bearer)
  API->>JWT: parseToken().authenticate()
  JWT-->>API: User
  API-->>FE: 200 {data: user}

  Note over FE,API: 4) Verified-only routes (api.auth:api + verified)
  FE->>API: PUT /api/profile  (Authorization: Bearer)
  API->>JWT: validate
  API->>API: middleware "verified" blocks if email_verified_at IS NULL
```

The middleware stack uses two guards in sequence: `api.auth` (the `ApiAuthenticate` middleware) validates the Bearer token via `tymon/jwt-auth` and hydrates the authenticated user; `verified` (the `EnsureEmailIsVerified` middleware) then checks `email_verified_at` and rejects the request with 403 if the field is null. Login attempts are rate-limited by `throttle:login` (5 requests per minute, keyed by email address or IP address) to prevent brute-force attacks.

Token windows are deliberately asymmetric: email verification tokens expire after 24 hours and password reset tokens after 1 hour. Both are stored as `EmailVerificationToken` rows in Postgres rather than being encoded in the JWT, allowing explicit revocation. Two convenience endpoints — `resendVerificationEmail` and `checkEmailVerificationStatus` — sit inside the `api.auth:api`-only route group (no rate limit and no `verified` guard), which was flagged as a gap in the security audit: a verified-but-compromised account could spam the resend endpoint.

The Google OAuth route (`POST /api/auth/google`) was removed in commit `79e3411` (audit finding R-09) because the corresponding `googleLogin` controller method was never implemented — the route was a leftover stub. The JWT package is pinned to `tymon/jwt-auth: dev-chore/laravel-12` for Laravel 12 compatibility; upgrade this dependency once an official release supports Laravel 12.
