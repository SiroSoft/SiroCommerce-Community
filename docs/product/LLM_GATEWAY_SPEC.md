# LLM Gateway Spec (P4.9 — provider impls behind AiSuggestionGateway)

Same interface, per-task policy: cloud for quality-critical tasks, local
Ollama for the rest. Budget ledger + audit stay in front of every call.

## Providers (`AI_PROVIDER`, default `unavailable`)

- `unavailable` — `UnavailableAiSuggestionGateway`, no external call.
- `openai` — OpenAI-compatible `POST {AI_BASE_URL}/chat/completions`
  (`Authorization: Bearer AI_API_KEY`, `model: AI_MODEL`). Covers OpenAI,
  Azure-compat endpoints and any OpenAI-compatible proxy.
- `ollama` — native `POST {AI_OLLAMA_URL|AI_BASE_URL}/api/chat`
  (`model: AI_OLLAMA_MODEL|AI_MODEL`, no key, `stream: false`).

Unknown value → `unavailable` (fail-closed). Missing URL/model/key (for
openai) → `provider_unavailable`, never a throw.

## Request flow (all in `AiSuggestionService`, unchanged)

`assertReadOnlyTask` → budget `allows` → `reserveMinor` → gateway →
audit `record`. Gateway impls never throw for provider errors: transport
failures, non-2xx, invalid JSON all map to `provider_unavailable` with a
short reason (no key/URL/body bytes in the reason).

## Safety rails (gateway-side)

- PII redaction: input passes `AnalyticsRedactor::redact` (drops
  email/name/phone/address/token/secret/password/card keys) before leaving
  the process; hard cap 8KB on the encoded input JSON.
- System prompt pins the contract: read-only merchandising assistant,
  JSON-only output, never invent prices/stock/URLs, no autonomous actions.
- `AI_TIMEOUT_SECONDS` (default 120), `AI_MAX_OUTPUT_TOKENS` (default 4096),
  temperature 0.2. Response `Cache-Control: private, no-store` (controller).
- Cost: usage tokens recorded in audit `usage_json`; spend is the reserved
  `max_cost_minor` (v1 accounting: reserve = spend).

## Budget ledger (`ai_budgets`: tenant PK, limit/spent, updated_at)

`remaining = limit - spent`. First use seeds `limit` from
`AI_BUDGET_MINOR` (default 0 = closed until the operator funds it).
`reserve` is one atomic `UPDATE ... WHERE spent + amount <= limit`.

## Audit (`ai_audits`: tenant/actor/task/status/provider/model/usage)

`DatabaseAiAuditWriter` inserts best-effort (never throws the request).
Admin read reuses the existing operations/reporting surfaces in v1 (no new
page).

## Tests

`AiGatewayTest` with an injected transport closure (no real HTTP):
openai happy path, ollama happy path, transport throw → unavailable,
non-2xx → unavailable, budget_exceeded without funding, denied mutation
task, audit row written. Unknown provider → unavailable.
