# Commerce Scope

Public CMS and headless requests require an explicit four-part scope:

- `X-Store-Id`
- `X-Channel-Id`
- `X-Locale`
- `X-Currency`

Query parameters with the equivalent names (`store_id`, `channel_id`, `locale`,
`currency`) are accepted for clients that cannot set headers. Supplying both
forms with different values is rejected as ambiguous. Missing or unsupported
values are rejected; the API does not silently fall back to a default scope.

The CMS page identity, published-content cache key, and CMS revision metadata
include the complete scope. Therefore equal slugs and locales can safely be
published independently per store/channel/currency without cross-tenant cache
reads.
