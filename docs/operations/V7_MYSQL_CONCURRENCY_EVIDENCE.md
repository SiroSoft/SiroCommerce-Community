> LEGACY (historical phase evidence; rerun the proof script before reuse). See [docs/README.md](../README.md).

# Phase V7 MySQL Concurrency Evidence

`backend/scripts/mysql-concurrency-proof.php` is the deterministic database
proof runner for BE_V2 sections 10-11. It opens two independent PDO
connections, preflights MySQL 8/InnoDB/isolation and the migrated inventory
schema, then creates and removes an isolated proof fixture. It proves guarded
ATP reservation, reservation idempotency, and inventory transfer idempotency
with real two-session lock and unique-key contention.

Run from `backend`:

```text
TEST_DB_DRIVER=mysql TEST_DB_HOST=127.0.0.1 TEST_DB_PORT=3306 TEST_DB_DATABASE=sirocommerce_test TEST_DB_USERNAME="<db-admin-user>" TEST_DB_PASSWORD= MYSQL_PROOF_EVIDENCE_FILE=../perf/artifacts/mysql-concurrency-proof.json php scripts/mysql-concurrency-proof.php
```

Run the PHPUnit wrapper for a clean migrated test schema. `Tests\\TestCase` drops
and reapplies every migration when `TEST_DB_DRIVER=mysql`; the proof script then
does its own fixture setup and cleanup. Do not point this at a production
database.

The JSON line is the evidence manifest. `PASS` requires two distinct connection
IDs, both preflight results, and all three inventory checks. `SKIP` means the
driver was not explicitly MySQL and is never release evidence. `BLOCK` means
MySQL was requested but the server, PDO driver, schema, engine, isolation, or
two-session prerequisite was unavailable. `FAIL` means the proof ran and an
invariant failed. Only `PASS` is certification evidence; preserve the JSON file
with the run artifact.

The focused manifest/preflight tests are in
`backend/tests/Unit/MySqlConcurrencyProofSchemaTest.php`. They reject a PASS
manifest that omits preflight, verified two-session evidence, or any inventory
proof.
