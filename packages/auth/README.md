# Browser Auth Storage

`browserAuthStorage` treats browser storage as a recoverable cache, not a trust boundary: access tokens are memory-only, malformed values are rejected, storage errors are contained, and logout is propagated across tabs.

The current JSON refresh contract requires the refresh token to remain in localStorage as a bounded fallback. It is never placed in URLs, logs, events, or error objects. A browser reload cannot restore an access token from storage; the UI fails closed and requires login unless a server session is introduced. Storage/private-mode/quota failures fail closed for future sessions, while the current in-memory session remains usable.

## Required HttpOnly Cookie Migration Contract

The later backend migration must provide all of the following before this fallback is removed:

- Login and refresh set `Secure; HttpOnly; SameSite=Lax` (or stricter) cookies scoped to the auth origin; token values are not returned in JSON.
- Logout revokes the server session and expires the cookie; it is idempotent when the cookie is absent.
- Cookie-authenticated state changes define an anti-CSRF token plus dedicated request header. The token is never in a URL.
- CORS allows only the configured admin origin with credentials, and refresh/logout return stable `401` behavior without revealing whether a credential existed.
- `/api/auth/me` is the authoritative session check after reload; no browser-readable access or refresh token is required.

Until that contract is implemented and tested, the admin remains on the JSON refresh fallback. This reduces localStorage exposure but does not make JavaScript-held refresh credentials safe from XSS; CSP, output encoding, sanitization, CSRF, and origin validation remain required.
