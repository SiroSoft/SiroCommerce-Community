# Tax Admin + Multi-rate Spec

The tax engine (`TaxRule` + `NativeTaxProvider`) already applies the most
specific rule with inclusive/exclusive and evidence. This adds operator control
and compound rates.

## Admin API (admin/owner/finance)

- `GET /api/admin/tax-rules` (`?state=&country=&tax_class=`)
- `POST /api/admin/tax-rules` (`code` unique, `destination_country` ISO2,
  `destination_region`, `destination_postal_code`, `tax_class`, `rate_basis_points`
  0..10000, `priority`, `state`, `compound`, `effective_at`, `expires_at`)
- `PUT /api/admin/tax-rules/{id}` (partial)
- `DELETE /api/admin/tax-rules/{id}` (allowed; engine ignores missing)
- Errors: 422 validation, 409 duplicate code.
- Windows are stored as `Y-m-d H:i:s` (inclusive start, exclusive expiry).
- Mutations append `tax.rule.created|updated|deleted` rows to
  `commerce_audit_events`.

## Engine change (backward compatible)

New column `compound` (0/1, default 0).
- No matching rule → 0 tax (production still fails closed, unchanged).
- Matching rules with `compound = 0` (all): keep current “most specific single
  rule” result.
- If any matching rule has `compound = 1`: apply every matching rule ordered by
  `priority ASC, rate_basis_points DESC`, summing tax per line; inclusive math
  applies per rate (`total * rate / (10000 + rate)`), then summed. Evidence lists
  all applied rule codes.
- Never let summed tax exceed the line total.

## Admin UI

`/tax` page (capability `finance`): table (country/region, class, rate %, priority,
compound, state, window) + create/edit dialog + delete ConfirmDialog. Sidebar
“Tax rules”.

## Tests

Single-rule unchanged; compound sums in priority order; inclusive compound;
non-matching → 0; window boundaries; duplicate code 409; invalid rate 422;
production fail-closed unchanged; quote evidence lists all codes.
