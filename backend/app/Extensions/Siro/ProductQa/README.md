# siro/product-qa

Free product Q&A extension. Shoppers ask a question on the product detail page
(PDP), admins moderate it, and approved answers render in the storefront PDP
slot. Follows `docs/contracts/EXTENSION_PACKAGING_SPEC.md`,
`docs/contracts/EXTENSION_UI_SPEC.md` and `docs/engineering/EXTENSION_SCOPING.md`.

## What it does

- Table `ext_product_qa` is scoped by `store_id` + `channel_id` and keyed by
  `product_id` / `product_slug`. Shared moderation state is
  `pending | approved | rejected`; `answered_at` records the decision time.
- Anonymous storefront routes:
  - `GET /api/ext/siro/product-qa/public?product_id=...` returns approved
    questions only for that product, newest answer first.
  - `POST /api/ext/siro/product-qa/public` creates a `pending` question.
    Requires `Idempotency-Key`; the same scoped key never duplicates a row.
- Admin routes (manifest permission `product-qa:manage`):
  - `GET /api/ext/siro/product-qa/admin?state=pending|approved|rejected|all`
    (optional `product_id`) lists questions.
  - `PUT /api/ext/siro/product-qa/admin/{id}` with `{"state":"approved"}`
    and/or `{"answer":"..."}` approves, rejects or answers a question.
    Repeating the same update is idempotent.

Every SELECT/UPDATE filters scope. Writes are merged through
`ExtensionScope::merge()`, so a caller cannot write into or read from another
store/channel.

## Input rules

- `product_id`: required, max 64 characters.
- `product_slug`: optional, max 160 characters.
- `customer_name`: 2-80 characters.
- `question`: 5-1000 characters.
- `answer`: 1-2000 characters.
- No HTML, control characters or `javascript:` payloads are accepted. The
  storefront renders answers as text with React, never via
  `dangerouslySetInnerHTML`.

## Install / enable

```bash
php siro extension:check
php siro extension:pack app/Extensions/Siro/ProductQa --out=dist/siro-product-qa-1.0.0.zip
php siro extension:verify dist/siro-product-qa-1.0.0.zip
php siro extension:qit dist/siro-product-qa-1.0.0.zip
# Admin -> Extensions -> siro/product-qa -> Install -> Enable
# (Enable runs migrations/20261123000002_create_ext_product_qa.php)
```

Disable unmounts all routes and keeps data. Uninstall with "remove data" runs
`down()` and drops `ext_product_qa`; core tables are never touched.

## Storefront

Storefront plugin: `apps/storefront/src/plugins/product-qa/`.
`register.ts` registers slot `pdp` with id `siro/product-qa` and priority 120.
The PDP passes `{ productId, productSlug }` via the existing `Slot` data prop.
The component fetches approved questions, renders them as text, validates the
form, submits with a generated `Idempotency-Key`, and shows a review notice on
success. Empty and unavailable states render safe fallbacks.

## Config

| Key | Default | Meaning |
| --- | --- | --- |
| `max_question_length` | 1000 | Documentation value; the validator enforces it. |
| `max_answer_length` | 2000 | Documentation value; the validator enforces it. |

No secrets, mailers or external providers. Admins answer inside the extension
admin panel/API.
