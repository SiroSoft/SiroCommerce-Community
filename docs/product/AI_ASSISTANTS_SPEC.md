# AI Assistants (P2.5 - staff + shopper)

Status: **MVP in progress**. Read-only, grounded assistants. They never execute
commerce mutations.

## Surfaces

| Plugin | Audience | Route | Storefront slot |
|---|---|---|---|
| `siro/staff-assistant` | Admin / staff | `POST /api/ext/siro/staff-assistant/admin/ask` | none |
| `siro/shopper-assistant` | Guest or signed-in shopper | `POST /api/ext/siro/shopper-assistant/public/ask` | `header` (floating widget) |

Both use the shared `AssistantService` and the existing
`AiSuggestionGateway` / `AiSuggestionService` rails: prompt redaction, tenant
budget ledger, audit writer, and fail-closed provider behavior.

## Multilingual pipeline

The core assistant service uses English as the pivot language.

1. The target answer language is the commerce scope locale (`X-Locale` /
   `locale`), normalized to its base language tag (for example `vi-VN` -> `vi`).
2. Supported languages come from `ASSISTANT_LANGUAGES` (comma-separated,
   default `en,vi,ja,de,zh` from `CommerceScope::ALLOWED_LOCALES`). English is
   always supported as the pivot. An unsupported target locale falls back to
   English.
3. When the target is not English, the question is translated to English with
   the read-only task `assistant.translate.in`.
4. The existing grounded assistant task (`assistant.staff` /
   `assistant.shopper`) answers in English with the same redacted context.
5. When the question was translated, the English answer is translated back with
   `assistant.translate.out`.

Translation reuses the configured `AiSuggestionGateway`; no second provider is
introduced. If a translation call is unavailable, over budget, or returns no
text, the service degrades safely:

- input failure -> the original question is used;
- output failure -> the English grounded answer is returned.

No fallback invents prices, stock, order data, or citations. Sources are always
taken from the server-provided context, never from model output. Every response
keeps `read_only: true` and `autonomous_mutation: false`, and the existing
question/context/answer length limits still apply.

## Request / response

Staff body: `question`, optional `order_id`, optional `product_id`, optional
`customer_id`.
Shopper body: `question`, optional `product_id`/`product_slug`, optional
`order_id` + `order_token` (guest order only). Signed-in customers use their
Bearer session.
All POSTs require `Idempotency-Key` because extension routes enforce it.

Response data:

```json
{
  "status": "available",
  "answer": "string|null",
  "sources": [{"label": "Product", "url": "/product/slug"}],
  "read_only": true,
  "autonomous_mutation": false,
  "reason": null
}
```

`status` is one of `available`, `provider_unavailable`, `budget_exceeded`,
`denied`. Provider errors never expose keys, prompts, or raw provider bodies.

## Staff permission matrix

The policy is evaluated from the authenticated user payload on the server. The
prompt/question text is never used to decide access. Owners have broad read-only
access; other roles follow the same capabilities used by the admin routes
(`orders`, `products`, `customers`), including any configured permission-set
override.

| Context | owner | admin | marketer | warehouse | support | finance | viewer |
|---|---|---|---|---|---|---|---|
| Order (`order_id`) | yes | yes | no | yes | yes | no | no |
| Product (`product_id`) | yes | yes | yes | no | no | no | no |
| Customer (`customer_id`) | yes | yes | no | no | yes | no | no |
| Customer PII (name/email) | yes | masked/excluded | masked/excluded | masked/excluded | masked/excluded | masked/excluded | masked/excluded |

Notes:

- Customer PII is an explicit owner-only permission. Non-owners get a masked
  name and no email; address, phone, payment, token and password fields are never
  included.
- A denied context returns `403 FORBIDDEN` before any lookup, so the response
  does not reveal whether the order/customer exists or belongs to another scope.
- Order and product context still resolve through their scoped repositories, so
  cross-tenant/cross-site IDs are not readable even for allowed roles.
- Customer profiles are global in the current schema, matching the existing
  admin customer detail endpoint.

## Context rules

- Staff: only the explicit order/product/customer whitelist (order state,
  totals, names, publish state; customer id/created_at and owner-only PII).
- Shopper: public catalog facts for the current product. Order facts are added
  only for the shopper's own order after ownership or a valid guest order token
  is verified. No payment, address, email, customer id or cross-customer data.
- The model must not invent prices, stock, URLs or order data. Sources returned
  to the client come from the server context, not from the model.
- Input context is capped at 6 KB; questions at 1,000 characters; answers at
  4,000 characters.

## Safety

- Read-only: no refund, price, inventory, order or catalog writes.
- Staff chat is an admin capability; shopper chat uses `optional-auth` and is
  throttled. Anonymous guests are accepted; order context still requires
  ownership/token verification.
- Existing `AI_PROVIDER=unavailable` default means both endpoints fail closed
  without an LLM provider.
- Future action proposals must land in the existing review queue and require a
  human approval; they are not part of this MVP.

## Non-goals (MVP)

- Autonomous writes or tool execution.
- Customer order lookup without authentication.
- Managed AI provider/billing, voice, image search, long-term memory.
- Training or fine-tuning on merchant data.

## Release notes

The two plugins are free in the MVP (BYO OpenAI-compatible or Ollama provider).
Managed provider/quota packaging remains a Pro/Enterprise follow-up. Catalog is
23 items after adding both plugins.
