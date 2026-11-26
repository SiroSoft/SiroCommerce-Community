# Shared Packages

Shared frontend boundaries live here. Apps may consume these packages, but
domain apps must not create competing transport, session, scope, query-key or
error-normalization layers.

Planned packages:

- `api-client`: generated SDK adapter and request coordinator.
- `auth`: access session, refresh single-flight and logout broadcast.
- `scope`: CommerceScope context and generation switching.
- `ui`: domain-neutral accessible primitives.
- `config`: environment and runtime configuration.
