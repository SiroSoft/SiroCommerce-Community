# Extension Store/Channel Scoping

Status: REQUIRED for every extension that persists data.

Extensions run inside one commerce deployment but serve many stores and
channels. A missing filter is a cross-tenant data leak, not a cosmetic bug.
Use `App\Modules\Extension\Application\ExtensionScope` for the persistence
scope instead of hand-writing `store_id` / `channel_id` fragments.

## 1. Scope contract

`CommerceScope` carries `store_id`, `channel_id`, `locale`, `currency`, plus
optional tenant/seller/site dimensions. Only `store_id` and `channel_id` belong
on extension rows; locale and currency are presentation facts.

```php
use App\Modules\Extension\Application\ExtensionScope;
use App\Support\CommerceScope;

$scope = CommerceScope::fromRequest($request);
$columns = ExtensionScope::columns();   // ['store_id' => 'store_id', 'channel_id' => 'channel_id']
$bound = ExtensionScope::fromScope($scope); // store_id + channel_id only
[$where, $params] = ExtensionScope::where($bound);
$rows = $db->prepare('SELECT * FROM ext_demo_rules WHERE ' . $where . ' AND active = 1')
    ->execute(array_merge($params, [1]));
```

Helper rules:

| Method | Contract |
|---|---|
| `columns()` | Canonical column names; use it in schema tests and query builders. |
| `fromScope(CommerceScope)` | Maps store/channel only; rejects empty values. |
| `where(array $scope)` | Returns exactly `['store_id = ? AND channel_id = ?', [$storeId, $channelId]]`; rejects missing/empty keys. Never interpolate values into SQL. |
| `merge(array $data, CommerceScope)` | Injects scope into writes. Matching scope is accepted; a different non-empty scope throws `CommerceScopeException` instead of being overwritten. |
| `assertScoped(array $row, CommerceScope)` | Fail-closed check for rows read from storage, job payloads and webhook payloads. Throws on missing, empty or foreign scope. |
| `isGlobal(array $manifest)` | True only for an explicit JSON boolean `"global": true`; `"true"`, `1`, missing all stay scoped. |

## 2. Schema checklist

Every `ext_*` table that stores extension data MUST:

- [ ] Include `store_id` (`string(64)`) and `channel_id` (`string(64)`), NOT NULL.
- [ ] Index the scope columns as the leading columns of every access path, e.g.
      `index(['store_id', 'channel_id', 'state'])`.
- [ ] Include scope in every unique constraint that protects an idempotent write
      (e.g. `unique(['store_id', 'channel_id', 'idempotency_key'])`). A global
      unique key silently blocks the same value in another store.
- [ ] Put scope in the `WHERE` clause of every `UPDATE` and `DELETE`. Primary-key
      updates alone are not sufficient.
- [ ] Keep `global` tables out of this pattern only with an approved review (see §4).

Reference: `app/Extensions/Siro/ShippingFeeRules/migrations/20261122000001_create_ext_shipping_fee_rules.php`.

## 3. Query checklist

- [ ] Every `SELECT`, `UPDATE`, `DELETE` against an `ext_*` table filters both
      `store_id` and `channel_id`, usually via `ExtensionScope::where()`.
- [ ] Every `INSERT` payload passes through `ExtensionScope::merge()`.
- [ ] Rows returned by id, unique key, job, webhook or join are checked with
      `ExtensionScope::assertScoped()` before they are trusted or mutated.
- [ ] No query uses a caller-supplied `store_id` / `channel_id` that did not come
      from `CommerceScope::fromRequest()` (or a trusted internal scope).
- [ ] `ORDER BY` / `LIMIT` queries still filter scope; pagination must not widen
      the result set.
- [ ] Aggregate and reporting queries group under the same scope predicate.
- [ ] Joins between two extension tables include equality on both scope columns.
- [ ] No cross-store fallback: empty or missing scope is an error, never a
      wildcard or a default store.

## 4. Global extensions

A manifest may declare `"global": true`. That is an explicit exception, not a
convenience flag. Before review approve it:

- [ ] The data is genuinely deployment-wide (e.g. provider health), not
      merchant-owned configuration.
- [ ] No row can be created, changed or read on behalf of one store and then
      observed by another.
- [ ] The QIT `store-scope` check and the reviewer agree the exception is
      intentional.
- [ ] The PR description names the global table(s) and why scoping is impossible.
- [ ] Tests cover the global path and prove it cannot be used to bypass a scoped
      endpoint.

Anything other than boolean `true` is treated as scoped.

## 5. QIT gate

`php siro extension:qit <package.zip>` runs the `ExtensionQualityGate`
pre-publish checks. Relevant to scope:

| Check | Enforces |
|---|---|
| `store-scope` | Every `Schema::create` table name starts with `ext_`. Unless the manifest has `global: true`, every create migration source must contain `store_id` and `channel_id`. |
| `package` / `signature` | Manifest/checksum/archive integrity before scope review. |
| `route-auth` | Mutating routes declare auth or capability middleware. |

QIT is a static first pass. It does not prove query filtering. Reviewers still
enforce §3, and the test matrix in §6 is mandatory.

## 6. Mandatory tests per extension

Add these to the extension's test suite (Unit or Feature as appropriate):

- [ ] Scoped list/find returns only the current store/channel; a second scope
      with the same business key is invisible.
- [ ] Create/update/delete merge or filter scope correctly; a mismatched
      `store_id` or `channel_id` is rejected.
- [ ] Reading a row by id with another scope returns `null` or throws via
      `assertScoped`, never the foreign row.
- [ ] Unique/idempotent keys are scoped, so the same key works in two stores.
- [ ] Global (if declared) is covered explicitly and cannot leak scoped rows.
- [ ] Tests exercise `ExtensionScope::where()` output is used, not a duplicated
      hand-written fragment that can drift.

Minimal helper test template:

```php
$scope = new CommerceScope('store-a', 'web', 'en', 'VND');
ExtensionScope::assertScoped($row, $scope);

$this->expectException(CommerceScopeException::class);
ExtensionScope::assertScoped($foreignRow, $scope);
```

## 7. Review stop signs

Reject the change if any of these appear:

- Raw `store_id = '...'` or `channel_id = '...'` interpolation.
- `WHERE id = ?` without scope on an `ext_*` table.
- `store_id`/`channel_id` read from request body without comparing to
  `CommerceScope`.
- Unique index missing scope.
- `global` added without the §4 justification.
- Scope behavior without a matching test.
