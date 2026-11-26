# Headless GraphQL Read API

`POST /api/headless/graphql` is a read-only, allowlisted GraphQL-style endpoint.

Supported root queries are `product(slug)`, `products(page, perPage)`, `page(slug, locale)`, and `order(id, token)`. Product detail exposes product type (`type`), options and values, variants, components, and digital asset metadata. Product lists expose pagination. Pages are published-only. Orders expose a summary only and require the authenticated owner or a valid guest access token.

Mutations are rejected with `READ_ONLY`. Queries are limited to 20,000 bytes, depth 8, and complexity 80. Errors use stable `code` values: `INVALID_REQUEST`, `PARSE_ERROR`, `VALIDATION_ERROR`, `READ_ONLY`, `DEPTH_LIMIT`, `COMPLEXITY_LIMIT`, `FORBIDDEN`, and `QUERY_TOO_LARGE`.

Variables are supplied as a JSON object and can be used for scalar string and integer arguments:

```json
{"query":"query Product($slug: String!) { item: product(slug: $slug) { ...ProductFields } } fragment ProductFields on Product { id name }","variables":{"slug":"shoe"}}
```

Aliases and named fragments are expanded before allowlist validation and complexity calculation. Fragment cycles, missing variables, unknown fragments, unsupported variable values, and invalid characters fail closed.

Clients may include `extensions.persistedQuery.sha256Hash`, containing the SHA-256 hash of the exact `query` string. The server verifies the hash statelessly; it does not store or execute a hash without the query.

The endpoint is throttled independently at 15 requests per minute per client IP, in addition to the storefront guard. `headless_graphql_requests_total` and `headless_graphql_rejections_total{code}` provide request and rejection telemetry. Oversized queries return 413 and authorization failures return 403; other GraphQL contract errors return 400.

Digital asset origin URLs and access keys are not part of the headless schema. Downloads must use an issued token through the protected download flow. The endpoint remains read-only and intentionally does not support subscriptions, mutations, checkout, customer profile reads, or search filters. It is a contract foundation rather than a replacement for the existing REST API.
