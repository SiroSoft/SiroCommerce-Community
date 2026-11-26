# Extension License Issuance

This runbook is for the vendor/operator who mints offline extension licenses for
SiroCommerce Pro and Enterprise deployments. A license is a signed JSON document;
merchant deployments verify it locally with `extension:license` and never call
home.

## Document contract

```json
{
  "payload": {
    "license_id": "lic_acme_2026",
    "customer_id": "cust_acme",
    "edition": "pro",
    "extensions": ["siro/alpha", "siro/beta"],
    "features": ["reports", "exports"],
    "limits": { "extensions": 5 },
    "issued_at": "2026-01-01T00:00:00Z",
    "not_before": "2026-01-01T00:00:00Z",
    "expires_at": "2027-01-01T00:00:00Z",
    "grace_days": 30,
    "kid": "vendor-key-2026"
  },
  "signature": "<base64 RSA-SHA256 signature>"
}
```

- Signature algorithm: RSA PKCS#1 v1.5 with SHA-256 (`OPENSSL_ALGO_SHA256`).
- The signature covers the canonical JSON encoding of `payload`
  (`JSON_UNESCAPED_SLASHES`, deterministic key order produced by the issuer).
  Verification re-encodes the decoded `payload` exactly the same way.
- Required fields: `license_id`, `customer_id`, `edition` (`pro` or
  `enterprise`), `extensions`, `issued_at`, `not_before`, `expires_at`,
  `grace_days`.
- Optional fields: `features`, `limits`, `kid`. `features` defaults to `[]`.
- `extensions` is a non-empty list or `"*"` for all extensions. `features` may be
  `"*"` for all features.
- Timestamps may be Unix seconds (integer/numeric string) or ISO8601 strings.
  The issuer normalizes them to UTC `YYYY-MM-DDTHH:MM:SSZ`.
- `grace_days` is a non-negative integer. The verifier computes
  `grace_until = expires_at + grace_days * 86400`.
- The issuer rejects payloads with an invalid validity window, including
  `expires_at` in the past, `not_before >= expires_at`, or
  `issued_at > expires_at`.

## Key generation

Generate an RSA key pair on a trusted issuance host. The private key never leaves
that host.

```bash
umask 077
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 \
  -out extension-license-private.pem
openssl pkey -in extension-license-private.pem -pubout \
  -out extension-license-public.pem
```

Use at least RSA 3072-bit keys. Store `kid` alongside the public key to identify
the signing key in support tickets and audits.

## Configuration

Issuer host (vendor/operator, never merchant production):

```env
EXTENSION_LICENSE_PRIVATE_KEY_FILE=/secure/siro/extension-license-private.pem
# or, when the PEM is injected by a secret manager:
# EXTENSION_LICENSE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n..."
```

Merchant runtime:

```env
EXTENSION_LICENSE_PUBLIC_KEY_FILE=/etc/siro/extension-license-public.pem
# or inline:
# EXTENSION_LICENSE_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n..."
EXTENSION_LICENSE_FILE=/etc/siro/extension-license.json
```

The issuer only needs the private key. Runtime deployments only need the public
key and the issued license. Never copy the private key to a merchant deployment,
admin UI, API response, log, ticket, or command-line argument.

## Issue a license

Create a request payload (without any signature):

```json
{
  "license_id": "lic_acme_2026",
  "customer_id": "cust_acme",
  "edition": "pro",
  "extensions": ["siro/alpha"],
  "features": ["reports"],
  "issued_at": "2026-01-01T00:00:00Z",
  "not_before": "2026-01-01T00:00:00Z",
  "expires_at": "2027-01-01T00:00:00Z",
  "grace_days": 30,
  "limits": { "extensions": 5 }
}
```

Issue to a file:

```bash
php siro extension:license:issue \
  --payload=license-request.json \
  --out=license.json
```

Emit a machine-readable result:

```bash
php siro extension:license:issue \
  --payload=license-request.json \
  --out=license.json \
  --json
```

Write the license document to stdout:

```bash
php siro extension:license:issue --payload=license-request.json > license.json
```

The command exits `1` on invalid payload, invalid/missing private key, signing
failure, or filesystem error. It refuses to overwrite an existing output file
unless `--force` is passed.

## Verify

Verify offline on the merchant deployment:

```bash
php siro extension:license /etc/siro/extension-license.json --json
php siro extension:license --json   # uses EXTENSION_LICENSE_FILE
```

Exit code `0` means community edition or a valid/in-grace Pro/Enterprise license.
Exit code `1` means the configured license is invalid, not yet valid, or expired
beyond grace. The JSON output includes `edition`, `license_id`, `extensions`,
`features`, `expires_at`, `grace_until`, `ok`, and `reason`. It never includes
key material.

## Expiry and grace

- Before `not_before`: `ok=false`, reason `not_yet_valid`.
- Between `not_before` and `expires_at`: `ok=true`, reason `valid`.
- After `expires_at` but before `grace_until`: `ok=true`, reason `grace`.
- After `grace_until`: `ok=false`, reason `expired`.

Expired licenses keep core commerce data intact. Update and marketplace support
paths refuse to proceed; admin shows the reason. Renew before `grace_until` to
avoid an interruption.

## Revoke and rotate

There is no online revocation list in the offline model.

- **Revoke a customer:** issue a replacement license that removes the extension
  or feature, or stop distributing updates. Short expiry windows make revocation
  controllable. Keep `license_id` for audit.
- **Revoke a leaked signing key:** rotate the key pair immediately. Generate a
  new pair, reissue every active license with the new private key, then replace
  `EXTENSION_LICENSE_PUBLIC_KEY`/`_FILE` on merchant deployments. Old signatures
  fail verification once the new public key is deployed.
- **Rotation:** runtime trusts one public key at a time. Schedule the public-key
  change together with reissued licenses; do not switch the public key before
  replacement licenses are ready. Retain the old private key only for the
  reissue window, then destroy it according to your key-custody policy.

## Custody checklist

- Private key lives only on the issuance host, protected by file permissions
  (`0600`) or an HSM/secret manager.
- Private key is never committed to Git, bundled into extension packages,
  embedded in containers, or passed on command lines.
- Only the public key is distributed to merchant deployments.
- Do not log request payloads containing secrets; license payloads are not
  secret, but signing keys are.
- Back up the public key and `kid`. Back up the private key only to an encrypted,
  access-controlled offline location.
- Record `license_id`, `customer_id`, `edition`, `kid`, and expiry in the license
  ledger for support and audit.
