# FE B2B Workflows

Status: `FE workflow contract`

## Screens and roles

| Screen | Roles/capabilities |
|---|---|
| Company directory/detail | Owner/admin; backend B2B capability required |
| Company members, approvals, catalogs | Owner/admin; delegated company-management capability |
| Contract and negotiated pricing | Owner/admin; commercial-management capability |
| Credit account and ledger | Finance/owner/admin; credit-management capability |
| Quote workspace | Sales/support/owner/admin; quote capability |
| B2B order approval | Company buyer/approver and sales/admin, according to backend authorization |

The role names are FE navigation defaults only. Hide unavailable actions, but
always handle backend `401/403`; never infer permission from the role label.

## Route mapping

Mapped operation count: **21**.

| Workflow | Route | operationId |
|---|---|---|
| Create company | `POST /api/admin/b2b/companies` | `post_api_admin_b2b_companies` |
| Approval scopes | `POST /api/admin/b2b/companies/{id}/approval-scopes` | `post_api_admin_b2b_companies_id_approval_scopes` |
| Catalogs/items | `POST /api/admin/b2b/companies/{id}/catalogs`, `POST /api/admin/b2b/companies/{id}/catalogs/{catalog}/items` | `post_api_admin_b2b_companies_id_catalogs`, `post_api_admin_b2b_companies_id_catalogs_catalog_items` |
| Contracts | `POST /api/admin/b2b/companies/{id}/contracts` | `post_api_admin_b2b_companies_id_contracts` |
| Credit read/account | `GET /api/admin/b2b/companies/{id}/credit`, `POST /api/admin/b2b/companies/{id}/credit-account` | `get_api_admin_b2b_companies_id_credit`, `post_api_admin_b2b_companies_id_credit_account` |
| Members/prices | `POST /api/admin/b2b/companies/{id}/members`, `POST /api/admin/b2b/companies/{id}/negotiated-prices` | `post_api_admin_b2b_companies_id_members`, `post_api_admin_b2b_companies_id_negotiated_prices` |
| Versioned company credit | `GET /api/v2/b2b/companies/{id}/credit` | `get_api_v2_b2b_companies_id_credit` |
| Quote creation/detail/actions | `POST /api/v2/b2b/companies/{id}/quotes`, `GET /api/v2/b2b/quotes/{id}`, `POST /api/v2/b2b/quotes/{id}/convert`, `POST /api/v2/b2b/quotes/{id}/{action}` | `post_api_v2_b2b_companies_id_quotes`, `get_api_v2_b2b_quotes_id`, `post_api_v2_b2b_quotes_id_convert`, `post_api_v2_b2b_quotes_id_action` |
| Contract/order approval | `POST /api/v2/b2b/contracts/{id}/approve`, `POST /api/v2/b2b/orders/{id}/approve`, `/approve-company`, `/draft`, `/reject`, `/submit`, `/transition` | `post_api_v2_b2b_contracts_id_approve`, `post_api_v2_b2b_orders_id_approve`, `post_api_v2_b2b_orders_id_approve_company`, `post_api_v2_b2b_orders_id_draft`, `post_api_v2_b2b_orders_id_reject`, `post_api_v2_b2b_orders_id_submit`, `post_api_v2_b2b_orders_id_transition` |

## State and conflict rules

The API exposes named quote/order actions, but the reviewed OpenAPI does not
provide complete quote, company, credit, or B2B-order state enums. The FE must
model `loading`, `draft`, `submitted`, `pending_approval`, `approved`,
`rejected`, `converted`, `credit_blocked`, `conflict`, and `failed` as display
states only when supported by returned data. Unknown values remain visible as
unknown and disable destructive actions.

Company, catalog, pricing, contract, credit, quote, and order writes must carry
the OpenAPI-declared idempotency behavior. Several admin B2B writes currently
mark idempotency as optional; the FE may send a stable key for a user action,
but must not claim replay safety unless the backend contract guarantees it.
For all writes:

- `409` means refresh the entity, preserve the draft, show the changed fields,
  and require user confirmation.
- A timeout means reconcile with the matching GET operation where available;
  never double-submit a quote/order or credit action.
- Scope, company membership, approval authority, credit availability, price
  selection, and transition legality are backend decisions.
- Sensitive credit data is shown only to the authorized audience and never put
  into analytics or URLs.

## Acceptance tests

1. A user without company capability gets a deliberate `403` state and cannot
   reach a mutation through a direct route.
2. Company scope cannot be changed by editing a company id in the URL.
3. Two approvers acting on the same quote/order produce one success and one
   recoverable conflict, not an overwrite.
4. Quote conversion is retried only with the same key after response loss and
   reconciles before a second attempt.
5. Credit screens show loading, empty, unavailable, blocked, conflict, and
   server-error states without inventing a credit-limit calculation.
6. Catalog/negotiated-price changes invalidate quote and order caches.
7. Mobile keyboard and screen-reader flows preserve unsaved quote/order drafts.

## Unresolved gaps

- No complete B2B state enums, capability matrix, list/read endpoints for
  companies, members, catalogs, contracts, or orders are present in OpenAPI.
- `{action}` and `transition` are open action names; allowed values and payloads
  must be documented before a strongly typed FE action menu is built.
- Credit semantics, approval routing, quote expiry, price precedence, and
  conversion side effects are not specified. Do not infer them from route names.
