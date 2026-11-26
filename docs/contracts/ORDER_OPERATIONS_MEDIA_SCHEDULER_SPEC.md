# Order Operations, Media, And Scheduler Specification

## Scope

This phase adds three production backend capabilities using native SiroPHP APIs:

- Order lifecycle mail notifications.
- Secure product image and video storage.
- Scheduled order and operational recovery tasks.

The implementation must preserve the existing `Controller -> Application -> Contracts -> Infrastructure` boundary.

## Order Mail

### Events

Support notifications for these existing domain events when available:

- `order.placed`
- `payment.captured`
- `payment.failed`
- `fulfillment.shipped`
- `fulfillment.delivered`
- `refund.completed`
- `return.resolved`

### Behavior

- Use native `Siro\\Core\\Mail` and `Siro\\Core\\Queue`.
- Resolve recipients from the customer email, or `guest_email` for guest orders.
- Skip delivery when the recipient is missing or invalid.
- Queue mail only after the originating transaction/outbox operation commits.
- Deduplicate using order ID, event type, and event version/idempotency key.
- Keep payment tokens, guest access tokens, credentials, and provider secrets out of templates and payloads.
- Mail provider failures must be retryable and must not fail the originating order API response.
- Templates must be reusable and testable independently from transport.

### Required Tests

- Event-to-template mapping.
- Valid customer and guest recipient resolution.
- Missing and invalid email handling.
- Duplicate event suppression.
- Queue payload shape and retry behavior.
- Sensitive data exclusion.

## Product Media Storage

### Supported Media

- Images: JPEG, PNG, WebP, and AVIF.
- Videos: MP4 and WebM.

Allowed MIME types and size limits must be configurable and enforced server-side.

### Storage Contract

- Use native `UploadedFile` and `Storage` APIs.
- Store files under `products/{product_id}/images/...` or `products/{product_id}/videos/...`.
- Persist only safe media metadata:
  - `id`
  - `product_id`
  - `type`
  - `path`
  - `mime_type`
  - `size`
  - `sort`
  - `alt`
  - timestamps
- Never expose raw filesystem paths, storage credentials, or internal access keys.
- Public responses may expose safe metadata and protected/signed URLs only.
- Upload, reorder, and delete operations require catalog administration capability.
- Delete must remove both the database record and the stored object, with recoverable failure handling.
- Existing product media schema and contracts must be reused; no parallel media system.

### Required Tests

- Valid image upload.
- Valid video upload.
- Unsupported MIME type.
- File size limit.
- Path traversal and unsafe filename handling.
- Unauthorized upload/delete.
- Delete cleanup for database and storage.
- Media ordering.
- Headless/public response leakage prevention.

## Order Scheduler

### Native Runtime

Use native SiroPHP `Schedule`, `Console`, and `Queue` APIs. Do not invent a scheduler abstraction if the installed framework already provides one.

### Scheduled Operations

- Expire stale pending orders.
- Release expired inventory reservations.
- Expire abandoned carts.
- Retry pending payment reconciliation.
- Dispatch pending outbox, webhook, and return-label work.
- Detect stuck payment, refund, and fulfillment operations.

### Behavior

- Every command supports `--dry-run`.
- Every operation is idempotent and state-machine aware.
- A distributed/process lock prevents overlapping executions.
- Slow or external-provider work is queued rather than executed in the scheduler process.
- Each run emits audit and metrics evidence.
- Failures are retryable and leave durable pending/failed state.
- Commands must report processed, skipped, failed, and recovered counts.

### Required Tests

- Dry-run does not mutate state.
- Repeated execution does not duplicate side effects.
- State transition guards are enforced.
- Lock prevents concurrent duplicate runs.
- Provider failure and retry recovery.
- Outbox/job dispatch recovery.
- Metrics and audit evidence.

## Shared Architecture Requirements

- Controllers use native `FormRequest` validation where supported.
- Responses use native `Resource` and response contracts.
- Provider, mail, storage, and scheduler dependencies are resolved through contracts/container bindings.
- Transactions and outbox writes are atomic where supported.
- External calls use durable pending/retryable operations.
- Migrations have unique timestamps and complete rollback behavior.
- OpenAPI and route coverage are updated for new endpoints.
- Sensitive media, order, customer, payment, and provider data is filtered from API responses, logs, mail, and queue payloads.

## Acceptance Criteria

- `php siro commerce:check --continue --json` passes.
- PHPStan passes with no new suppressions.
- Existing behavior remains compatible unless explicitly documented.
- Feature/integration tests cover all three capability groups.
- Queue and storage tests do not call real external services.
- OpenAPI and API coverage pass.
- Compatibility and production operations documentation is updated.
- The worktree contains only intentional changes.

## Explicit Residuals

- A real SMTP/provider configuration is required for production mail delivery.
- Video transcoding and thumbnail generation are outside this phase.
- Scheduler execution requires a production worker/supervisor or cron configuration.
- Signed URL behavior depends on the configured production storage driver; local storage remains for development and tests.
