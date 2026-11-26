# API-1 Test Run Evidence

Run date: 2026-09-21. Commands were run from `backend/`.

| Layer/gate | Result |
|---|---:|
| Unit | 132 passed, 361 assertions, 0 failed, 0 skipped |
| Integration | 93 passed, 308 assertions, 0 failed, 1 skipped |
| API/Component | 1 passed, 11 assertions, 0 failed, 0 skipped |
| Workflow | 1 passed, 5 assertions, 0 failed, 0 skipped |
| Full PHPUnit | 832 passed, 0 failed, 1 skipped |
| PHPStan max | passed, 0 errors |
| OpenAPI contract/parity | passed, 180 operations / 166 paths |
| SDK parity | passed, 180 operations |
| Handoff semantic matrix | 900 passed, 0 blocked, 0 skipped |

The integration skip is emitted by the existing MySQL/provider/schema-dependent
test and is not converted to a pass. Every route now has exact semantic
evidence for all five required layers, so handoff readiness is `READY`.

The full suite uses a 512M PHPUnit memory ceiling because the exact evidence
matrix expands the suite to 818 cases. Framework cache is reset between tests
and API correlation middleware is prioritized ahead of auth failures.
