# API documentation (OpenAPI)

`openapi.json` is the machine-readable OpenAPI 3.1 spec for the backend API,
generated from the typed controllers and FormRequests by
[Scramble](https://scramble.dedoc.co/). It is the contract the `frontend-next`
repo consumes (types, request/response shapes, auth requirements).

## Coverage

All 39 operations across the public API: auth, links CRUD, link metadata,
analytics, public shorten/analytics, and subdomains.

- **Auth:** documented as HTTP **bearer (JWT)**. Endpoints behind the `api.auth`
  middleware require `Authorization: Bearer <token>`; the 9 public endpoints
  (login, register, verify/forgot/reset password, auth0-exchange, public shorten,
  public link, public analytics) are marked open.
- **Envelopes:** success responses use `{ data, meta?, message? }` and errors use
  `{ error: { code, message, details? } }`, applied by the `NormalizeApiResponse`
  middleware. Note: Scramble infers the controllers' raw return shapes, so a few
  responses may show the pre-envelope payload — treat the envelope as authoritative.

## Regenerating

Scramble is a **dev-only** dependency (no production runtime footprint — prod runs
`composer install --no-dev`). Regenerate the static spec after changing any
endpoint, request, or resource:

```bash
docker compose exec -T app php artisan scramble:export --path=docs/api/openapi.json
```

Sanity-check that the docs generate without errors:

```bash
docker compose exec -T app php artisan scramble:analyze
```

Configuration (title, version, description, the `api.auth` security strategy)
lives in `config/scramble.php`. In local dev the interactive docs UI is also
available at `/docs/api`.
