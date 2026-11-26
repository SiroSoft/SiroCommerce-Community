# Native Headless, Reporting, and AI Contracts

## Headless

`GET /api/headless/schema` publishes the native read-only GraphQL field contract and limits. `POST /api/headless/graphql` accepts body-only documents, persisted-query hashes, explicit commerce scope, and no mutations. The published limits are 20,000 query bytes, depth 8, and complexity 80.

## Reporting

`GET /api/v2/admin/reports/contract` publishes the read-only reporting contract. Dashboard dimensions and BI export datasets are provider-neutral, scoped by tenant/store/channel where applicable, and bounded to a 366-day window. Export records remain redacted and resumable; provider payloads and direct customer contact fields are not part of the contract.

## AI suggestions

`POST /api/v2/admin/ai/suggestions` is a suggestions-only seam. Requests require an explicit tenant scope, a bounded task name, an input object, and a tenant budget. Every attempt has an audit boundary and returns `autonomous_mutation: false`.

The installed native gateway is deliberately unavailable and performs no external model call. Host extensions may implement `AiSuggestionGateway`, `AiAuditWriter`, and `AiCostLedger`, but the policy rejects financial or autonomous mutation tasks before the gateway is reached. No AI contract can place orders, capture/refund/void payments, or transition financial state.
