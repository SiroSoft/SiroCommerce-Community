# OpenAPI breaking-change gate

The API contract check proves that the generated and public OpenAPI documents are synchronized. The separate breaking-change gate compares the proposed document with an explicit release baseline and blocks consumer-breaking changes.

Run from `backend/`:

```sh
php scripts/check-api-breaking.php --baseline=/path/to/released-openapi.json --current=docs/openapi.json
```

The gate reports removed paths or operations, changed operation IDs, removed or newly required parameters, newly required request bodies, removed response statuses, strengthened security, removed schemas/properties, required-property additions, and incompatible property types. Additive endpoints, fields, and response statuses pass.

The baseline must be the OpenAPI artifact from the release being upgraded. No network request, provider call, worker start, or production claim is made by this check. A missing baseline is a usage error (`exit 2`); detected incompatibilities return `exit 1`; a clean comparison returns `PASS`.

CI should run `check:api-contract` first, then `check:api-breaking` with the immutable release baseline supplied by the release pipeline.
