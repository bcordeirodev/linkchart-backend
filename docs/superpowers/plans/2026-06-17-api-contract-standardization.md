# Plan — API response/exception standardization (frontend-coordinated)

**Status:** proposed, needs frontend coordination · **Source findings:** F-7/F-10/F-11/F-14, #10/#11 in `docs/audits/2026-06-17-backend-code-review.md`

## Why this is not a backend-only refactor

The `frontend-next` repo (separate Git repo) consumes the API's response shapes and
HTTP status codes directly. The improvements below change **observable contract**, so
they cannot be merged backend-first without breaking the frontend. They must land in
**lockstep** with a frontend release. The new OpenAPI spec (`docs/api/openapi.json`)
documents the **current** contract and is the baseline to diff against.

## Current state (the debt)

- **5–7 envelope shapes** coexist upstream of `NormalizeApiResponse`, which papers
  over them at the edge (and mis-nests a couple of cases): `{data}`, `{message,data}`,
  `{success:true,data}`, `{success,message,user,token}`, `{error:string,message,errors}`,
  `{error:{code,message}}`, and a bare un-enveloped cached array.
- **`AuthController`: 14 `try/catch (\Exception) → 500`** blocks, each with its own
  `uniqid()` id and envelope, duplicating the central renderer in `bootstrap/app.php`.
- **FormRequests override `failedValidation()`** with a legacy `{error,errors}` shape
  that bypasses the central `ValidationException` renderer.
- **No domain exceptions:** slug-conflict (should be **409**) and invalid-URL (**422**)
  both throw `\InvalidArgumentException` and both map to 422.

## Target contract

- Success: `{ data, meta?, message? }` — owned by `NormalizeApiResponse`.
- Error: `{ error: { code, message, details? } }` — owned by the central handler.
- Correct status codes per failure (e.g. 409 for slug conflict, 422 for validation).

## Migration — two stages, only stage 2 touches the contract

### Stage 1 — Internal-only (safe, backend-mergeable now)

No observable change; pure cleanup that removes the duplication without altering
shapes or status codes. Can ship without frontend coordination.

1. Introduce a small set of domain exceptions (`DomainException` base +
   `SlugAlreadyTakenException`, `UrlUnsafeException`, …). Give each a `render()`
   method (or register a renderer) that returns **the current** status code + shape,
   so behavior is byte-identical for now. This lets `LinkService`/`LinkController`
   throw typed exceptions instead of generic `\InvalidArgumentException`.
2. Delete the 14 `AuthController` `try/catch → 500` blocks and let exceptions bubble
   to the central `\Throwable` renderer — but first confirm the central renderer
   reproduces the **current** auth 500 shape, or temporarily map it to match.
3. Keep `failedValidation()` for now (it's observable) — only remove it in Stage 2.

Guard everything with characterization tests that snapshot the current responses
(status + body) for the affected endpoints before refactoring.

### Stage 2 — Contract change (coordinated with frontend release)

1. Agree the canonical envelope + corrected status codes with the frontend, using the
   OpenAPI spec as the diff baseline.
2. Backend PR: push auth `token`/`user` under `data`; remove the `failedValidation()`
   overrides (central `ValidationException` renderer takes over); switch slug-conflict
   to 409; drop the dead `success:true` keys; normalize the remaining bare/escape-hatch
   responses (`getClicksList`, the cached `$basicData`).
3. Frontend PR (same release window): update the API client / error handling to the
   canonical envelope and the new status codes.
4. Regenerate `docs/api/openapi.json` so the spec reflects the new contract.

## Notes

- The earlier blocker (the `AuthController` cookie-auth WIP) is now committed on `main`,
  so Stage 1 no longer conflicts with in-flight work.
- `#12 LinkPolicy` is intentionally **excluded**: the manual ownership checks return
  **404** by design (no existence disclosure) and are tested; `authorize()` returns 403,
  which would be a security regression. Leave the current behavior.
