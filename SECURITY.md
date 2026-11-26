# Security Policy

## Supported versions

Security fixes target the latest `v0.1.x` Community release line. Older tags may
receive guidance but are not guaranteed backports.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability.

Use GitHub **Private vulnerability reporting** on the repository:

1. Open the repository Security tab.
2. Choose **Report a vulnerability**.
3. Include affected version/tag, impact, reproduction steps and any suggested
   fix.

If private reporting is unavailable, contact the maintainers through the private
SiroSoft channels. Never include production secrets, customer data, license
private keys or full payment payloads in a report.

## Scope

In scope:

- authentication/authorization bypass, privilege escalation;
- payment, refund, order-state or inventory integrity;
- extension/theme package verification, QIT, signature and license enforcement;
- tenant/store/channel scope escape;
- SQL injection, SSRF, path traversal, unsafe file upload, stored XSS;
- secret leakage in source, logs, build artifacts or public feeds.

Out of scope:

- vulnerabilities in third-party services without a Siro-side fix;
- social engineering, spam or physical access;
- reports requiring a compromised merchant device or stolen admin credentials
  without a separate platform weakness;
- denial of service from unrealistic traffic without a reproducible bottleneck.

## Our handling

- We aim to acknowledge a report within 3 business days.
- We will assess severity, prepare a fix and coordinate disclosure timing.
- Please give us reasonable time before public disclosure.
- Credit is provided on request.

## Secret handling

Never commit `.env`, private keys, signing keys, license documents, customer
data or production exports. If a secret is exposed, rotate it immediately; do
not rely only on deleting the file from the latest commit.

## Pro and licensed components

Pro source and license issuance tooling are private. Security reports about
licensed components use the same private channel. Do not publish paid source or
signed artifacts while investigating.
