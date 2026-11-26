# Frontend Test Coverage

Coverage is an explicit, report-only check for the Admin and Storefront apps.
The normal `test` scripts remain uninstrumented so local regression runs stay
fast.

```text
npm run test:coverage --workspace apps/admin
npm run test:coverage --workspace apps/storefront
```

The command uses Vitest's V8 provider and writes text, HTML, and JSON summary
reports under each app's `coverage/` directory. Test files and declarations
are excluded; application TypeScript is included so uncovered modules remain
visible.

## Baseline Policy

Coverage is intentionally report-only until the current application baseline
has been reviewed. The proposed next gate is a ratcheting threshold: set each
app's line and branch thresholds to its measured baseline, then raise them by
at least 2 percentage points per quarter. Do not lower a threshold to make a
coverage run pass.

The baseline must be refreshed with the commands above after changes to test
selection or the `src/` include/exclude rules. A future enforced threshold
should be added only after the baseline has been reviewed and the corresponding
critical-domain tests are present.
