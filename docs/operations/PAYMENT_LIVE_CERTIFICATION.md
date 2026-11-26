# Payment Live Certification

Run from `backend` with `composer certify:payment -- --output=storage/payment-certification.json`.

The command is side-effect free and never contacts a provider. It reports the selected provider, mode, capability set, missing credential variable names, deterministic sandbox lifecycle results, webhook signature/tamper checks, and reconciliation fail-closed evidence. Secrets, tokens, authorization headers, and provider response bodies are not included.

Exit codes:

- `0`: all required checks passed and explicit live evidence was enabled.
- `1`: a payment invariant or configuration check failed.
- `2`: external certification is blocked. This is the expected result when live credentials or explicit `PAYMENT_CERTIFICATION_LIVE=1` are absent. The report is not a live certification claim.

`PAYMENT_CERTIFICATION_SANDBOX=1` runs deterministic authorize, capture, refund, dispute-capability, signed webhook tamper, money, and reconciliation checks without changing orders or payment records. Sandbox success does not certify live credentials or provider connectivity.
