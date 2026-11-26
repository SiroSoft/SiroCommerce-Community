# Frontend Security And Privacy

Status: `FE DESIGN BASELINE`

This is the browser security contract for SiroCommerce. It covers session
handling, token exposure, cross-tab behavior, XSS/CSRF defenses, privacy,
redaction, and telemetry. It does not weaken backend controls.

## Browser Session Architecture

Use a single session coordinator per browser profile:

```text
UI -> generated SDK -> request coordinator -> session coordinator
                                      |-> scope/cache policy
                                      |-> redaction/telemetry
```

The access token is short-lived JWT material returned by
`post_api_auth_login`/`post_api_auth_refresh`. The refresh API accepts a JSON
`refresh` value and returns rotated tokens. The backend stores refresh-token
records, revokes the used refresh token during rotation, and logout revokes all
refresh tokens for the user and bumps `token_version`.

### Token Storage Policy

- Preferred: keep the access token in memory only.
- Preferred refresh storage: an `HttpOnly`, `Secure`, `SameSite` cookie owned by the auth origin, if the deployment supports cookie-based refresh transport.
- Current JSON refresh contract: access tokens are memory-only; the refresh value remains only in the bounded localStorage fallback required by the current API. It is never put in a URL, analytics event, Redux persistence, IndexedDB, sessionStorage, or an error object. A reload without a server session fails closed rather than restoring an access token.
- Never log access tokens, refresh tokens, JWT claims, `Authorization`, `X-Siro-FE`, cart tokens, webhook signatures, payment data, or download tokens.
- One-time verification/reset tokens may arrive from the existing email-link contract, but the admin must immediately scrub them from browser history/referrer state after reading them and must submit them only in the request body.
- Do not decode JWT claims in the UI as an authorization decision. Treat `/api/auth/me` and backend denial as authoritative.

The current API contract exposes a JSON refresh field rather than documenting a
cookie endpoint. This is an unresolved hardening gap for a browser-only client;
see the final gap list.

## Refresh Rotation And Locking

The coordinator must implement a single-flight refresh lock:

1. On `401` caused by an expired access token, the first request creates a shared refresh promise.
2. Other requests await that promise rather than calling `post_api_auth_refresh`.
3. The lock carries the old token generation and a short deadline; it is always released in `finally`.
4. On success, atomically replace the in-memory access token and refresh material, increment generation, then replay each eligible original request once.
5. On `401` from refresh, token rotation reuse, malformed refresh, or timeout, clear the session and broadcast logout.
6. Never replay login, register, refresh, logout, webhook, or a mutation that has already produced an unknown server outcome.
7. Preserve the original `X-Correlation-Id` only for a safe replay; add a client retry marker to telemetry, not to business payloads.

Use bounded exponential backoff only for network transport failures where the
operation is safe. A refresh lock is not a permission bypass and must not retry

## Revocation And Multi-Tab Logout

Use `BroadcastChannel` where available, with a storage-event fallback carrying
only a random event ID, event type, and timestamp. Never broadcast tokens or
PII.

Events:

| Event | Sender behavior | Receiver behavior |
|---|---|---|
| `session-logout` | Clear access/refresh state, scoped caches, drafts, and pending protected work | Do the same once; show signed-out state without a refresh attempt |
| `session-revoked` | Same as logout; include only a reason code such as `UNAUTHORIZED` | Same as logout |
| `session-updated` | Publish generation only, never token material | Re-read shared session state through the coordinator; do not copy secrets from the event |

Logout calls `post_api_auth_logout` when an authenticated access token exists,
then clears local state even if the network call fails. A logout response must
not be blocked by a refresh race. All tabs must stop polling and invalidate
protected caches after the event.

## CSRF

Bearer headers are not automatically sent by cross-site requests, but CSRF
still applies if refresh or session credentials use cookies.

- Use `SameSite=Lax` or `Strict` as compatible with the product flow and `Secure` in HTTPS environments.
- Use an anti-CSRF token for cookie-authenticated state-changing requests if the backend contract provides one; send it in a dedicated header, never in a URL.
- Enforce exact CORS origins, not `*`, for credentialed requests.
- Reject cross-origin state-changing requests at the boundary; preflight must not reveal secrets.
- Do not treat `X-Siro-FE` as CSRF protection. It is a frontend trust guard, and its secret must remain server-side or in a protected runtime boundary.
- Do not add GET actions that mutate state.

The current route file has no explicit browser CSRF token header in the FE
handoff. Cookie-based refresh therefore requires backend contract confirmation
before production enablement.

## XSS And Untrusted Content

- Render API strings as text by default; do not use raw HTML injection for CMS, reviews, customer names, search terms, or error messages.
- If rich CMS content must render, sanitize with a reviewed allowlist, remove scripts/event attributes/unsafe URLs, and apply a strict Content Security Policy.
- Do not interpolate user-controlled values into script, style, URL, or HTML contexts without context-appropriate escaping.
- Use Trusted Types where supported for DOM sinks.
- Keep source maps and debug panels out of production if they could expose response bodies or tokens.
- Treat downloaded filenames, content types, provider errors, and redirect targets as untrusted.

## Stable Errors And Redaction

The client branches on HTTP status and `meta.error_code` only:

```text
400 malformed request
401 UNAUTHORIZED
403 FORBIDDEN
404 NOT_FOUND
409 CONFLICT
422 VALIDATION_ERROR
429 throttled, honor Retry-After
500 INTERNAL_ERROR
503 TRUST_CONFIG_MISSING when frontend trust is unavailable
```

Redact before logs, telemetry, crash reports, support bundles, and clipboard
diagnostics:

- credentials, tokens, cookies, authorization headers, `X-Siro-FE`, CSRF values, signatures, cart tokens, download tokens;
- passwords, Turnstile responses, payment instrument data, gateway references;
- full email, phone, postal address, customer notes, order line details, and free-form review/CMS content unless an approved support workflow explicitly needs it;
- request/response bodies by default.

Allowed diagnostic fields are operation ID, method, API version, status,
stable error code, correlation ID, scope hash or non-sensitive scope labels,
client version, environment, latency bucket, and retry count. Hash or truncate
identifiers with a keyed server-side strategy; do not use raw customer/order
IDs as analytics dimensions unless the approved control explicitly allows it.

Provider details must remain server-side. The backend `PublicErrorMessage`
policy removes infrastructure and provider details from public messages; the FE
must not reconstitute or display hidden exception fields.

## Privacy And Data Lifecycle

- Collect only fields required by the active operation and screen.
- Do not submit arbitrary customer IDs for customer-owned operations; identity comes from the access token.
- Mask email, phone, address, and order identifiers in list views and telemetry.
- Clear sensitive form state after submit, route leave, logout, scope change, and session revocation.
- Do not persist account, order, payment, or CMS response bodies in browser storage.
- Privacy export and anonymization require confirmation, clear impact text, idempotent UX, and a post-action status screen using `get_api_account_privacy_export` and `post_api_account_privacy_anonymize`.
- Legal-hold UI is restricted to the exact finance role/capability route and must show an audit/reason confirmation before mutation.
- Consent UI must identify purpose, version, timestamp, and withdrawal behavior; use `post_api_account_consents`.
- Analytics tracking must be consent-aware and must never include tokens, raw PII, or full request payloads.

## Headers And Transport Controls

Every request goes through the generated SDK coordinator and applies the
documented `Accept`, `Content-Type`, `Authorization`, `X-Correlation-Id`, FE
guard, idempotency, cart-token, and CommerceScope headers. The browser must:

- use HTTPS outside local development;
- set `Referrer-Policy: strict-origin-when-cross-origin` or stricter;
- use a restrictive CSP, frame-ancestors policy, and MIME-sniffing protection;
- avoid third-party scripts on authenticated/admin surfaces unless approved and isolated;
- apply timeout, cancellation, and response-size limits;
- preserve `X-Correlation-ID` for support without exposing response internals.

## Telemetry Controls

Telemetry events include only:

```text
event_name, operationId, status_class, error_code, correlation_id,
scope_label/hash, role label where approved, client_version, duration_bucket
```

Use an allowlist schema. Drop unknown fields. Sample successful high-volume
reads, retain security failures at a controlled rate, and never record raw
headers, URLs containing tokens, request bodies, response bodies, or free text.
Access to telemetry is role-restricted and retention is documented by the
environment owner.

## Acceptance Tests

- Two simultaneous expired-token requests produce exactly one refresh request and each safe request replays at most once.
- A refresh failure clears every tab and no protected request is sent after `session-revoked`.
- Reusing a rotated refresh token results in a signed-out state, not an infinite refresh loop.
- Logout revokes local access immediately and clears protected query caches, drafts, and polling.
- No automated test or production telemetry contains `Authorization`, refresh/access tokens, cart tokens, `X-Siro-FE`, CSRF values, passwords, payment data, or raw PII.
- A cookie-authenticated mutation without the approved CSRF control is blocked in browser integration tests.
- Untrusted CMS/review/error content cannot execute script or create unsafe navigation.
- `401`, `403`, `409`, `422`, `429`, `500`, and `503` fixtures produce stable, accessible UI states based on error codes, not messages.
- A scope switch and logout race cannot leave protected data visible under the next identity.
- Privacy export/anonymization/consent screens are redacted in screenshots, logs, support bundles, and analytics.

## Unresolved Gaps

1. The browser refresh contract currently accepts a JSON refresh value. The exact HttpOnly-cookie, logout, CORS, `/api/auth/me`, and CSRF migration contract is documented in `packages/auth/README.md`; implement and verify it before removing the JSON fallback.
2. The OpenAPI contract exposes security schemes but not complete role/capability metadata for every operation. Role mappings therefore remain derived from `routes/api.php`; add generated authorization metadata to close drift risk.
3. `x-scope` marks 63 operations in current source (2026-11-22), while backend scope enforcement may cover additional admin families. Require OpenAPI scope metadata parity before declaring cache isolation complete.
4. Live provider certification is excluded from FE completion. Provider-dependent UI states remain required and need contract fixtures, not live credentials.
5. A browser-level CSP, CORS allowlist, cookie attributes, telemetry retention, and third-party script inventory are deployment controls not evidenced by the current FE repository.
