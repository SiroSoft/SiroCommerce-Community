# Analytics Integration Contract

Analytics evidence is versioned at schema `1`. The supported event names cover orders, payments, refunds, subscriptions, B2B approval decisions, promotions, and fulfillment outcomes. Event payloads are stored as JSON after recursive removal of contact, address, credential, payment-card, token, and raw-provider fields.

## Read and Export

`GET /api/admin/analytics/events` returns a paginated, redacted read model. `GET /api/admin/analytics/export` returns the same records in a provider-neutral envelope:

```json
{"schema_version":1,"exported_at":"RFC3339","records":[{"event":"order.placed","schema_version":1,"payload":{}}]}
```

Both endpoints require an authenticated `admin`, `owner`, or `marketer` with the `analytics` capability, accept `from`, `to`, `event`, `page`, and `per_page`, cap the date window at 31 days and page size at 100, and expose no PII by default. Consumers should treat `event_id` as the replay/deduplication key and ignore unknown fields.

The analytics listener derives a deterministic event ID from the versioned event name and redacted payload. Replayed domain or outbox delivery therefore returns the existing row instead of adding evidence twice. Provider webhooks retain their existing provider/event-id deduplication boundary.
