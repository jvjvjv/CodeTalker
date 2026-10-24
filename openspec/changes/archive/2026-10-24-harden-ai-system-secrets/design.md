## Context

See proposal.md for motivation. The facts that shape the approach:

- `AiSystem::casts()` already declares `api_key => encrypted`, `credentials => encrypted:array`, `web_tool_policy => encrypted:array`. This change adds no encryption; it stops the decrypted values leaking through serialization and fixes the one column that cannot hold its own ciphertext.
- Column types today: `api_key` `text`, `web_tool_policy` `text`, `credentials` `json` (from `2026_05_06_154337_add_provider_capabilities_to_ai_systems_and_logs_tables`).
- Nothing in `src/` serializes an `AiSystem`. Every reader uses attribute access: `AiSystemProviderConfigurator` (`api_key`), `ToolContext::webToolPolicy()` (`web_tool_policy`). `AiSystemManager::duplicate()` uses `replicate()`, which copies raw attributes and ignores `$hidden`. Nothing in `src/` reads `credentials` at all — it is written by the manager and held for hosts.
- `AiSystemManager::updateRules()` does not accept `api_key`, but does accept `credentials` and `web_tool_policy` as `nullable|json`.
- An unset `web_tool_policy` means *unrestricted* (`ToolContext::webToolPolicy()`), not "use a default".
- The test suite runs on SQLite only, where a `json` column is untyped text. The `json`-versus-ciphertext conflict cannot be reproduced there; it follows from MySQL, MariaDB, and PostgreSQL validating JSON columns on write.
- The package both loads its migrations (`loadMigrationsFrom`) and publishes them.

## Goals / Non-Goals

**Goals:**

- The three attributes never appear in `toArray()` / `toJson()` output unless a host asks for them.
- `credentials` can be written through the model on every database Laravel supports.
- No behavior change for any code that reads the attributes directly.

**Non-Goals:**

- Repairing rows whose `credentials` were written around the model as plain JSON. Such a row already throws on read today; this change neither fixes nor worsens that.
- Changing `update()` so a blank `credentials` / `web_tool_policy` means "keep". See Risks.
- A split representation of `web_tool_policy` that exposes `allowed_domains` while hiding `credentials`.
- Auditing other models for hidden attributes.

## Decisions

### Hide with `$hidden`, matching `AiMcpServer`

`AiMcpServer` hides `auth` with a plain `$hidden` property and pins it with `assertArrayNotHasKey('auth', $server->toArray())`. `AiSystem` follows the same pattern for the same reason, so the two models read alike.

*Alternative — an API resource / presenter that whitelists fields.* Rejected: the package ships no controllers, so it cannot make hosts use a resource. `$hidden` is the only default that protects a host who does the obvious thing.

*Alternative — `$visible` (allow-list).* Rejected: every future column would be invisible until listed, a much larger break than three keys.

### Hide `web_tool_policy` whole

Its `credentials` map is the secret; `allowed_domains` is not. Hiding the whole attribute is the requested behavior and the only option that needs no new accessor or serialization hook. A host that wants to show the allow-list reads `$system->web_tool_policy['allowed_domains']`.

*Alternative — an appended, redacted `web_tool_policy` (domains plus host names with header values masked).* Rejected for this change: it adds a second public shape for the same data that the README and host forms would then have to distinguish from the writable one.

### Change the column with a new migration, never by editing the original

Hosts have already run `2026_05_06_154337_…`. The fix is a new migration, `2026_10_12_000001_change_ai_systems_credentials_to_text`, doing `$table->text('credentials')->nullable()->change()`. Laravel 12 and 13 change column types natively (no `doctrine/dbal`), including on SQLite, where it rebuilds the table.

`text` rather than `longText`: it matches `api_key` and `web_tool_policy`, and an encrypted credentials map is well under 64 KB.

Values are carried across as-is. On a validating database the column can only contain NULL or plain JSON (the model could never write to it), and the type change preserves either. On SQLite it may already contain ciphertext, which is preserved too.

### `down()` leaves the column as `text`

Reverting to `json` fails outright on a validating database once any row holds ciphertext, and the only way to make it succeed is to discard those rows' credentials. A rollback that destroys secrets is worse than one that does nothing, so `down()` is a documented no-op. A `text` column is a superset of what the old schema could store, so code from before this change still runs against it.

### Pin the column type against the SQL a validating database is sent

SQLite cannot reproduce the original failure, and it cannot confirm the fix either: `Schema::getColumnType()` reports `text` for a `json` column there, so a column-type assertion passes before the migration exists. The regression test instead runs the migration's `up()` in pretend mode on a MySQL connection — which compiles the statement without connecting — and asserts it is ``alter table `ai_systems` modify `credentials` text null``. A round-trip test (write a map, assert the raw column value is not the JSON, assert the attribute reads back) covers the behavior on the database the suite does have.

## Risks / Trade-offs

- **A host edit form that round-trips `web_tool_policy` could clear it.** If a form was prefilled from the serialized system, it now gets nothing, and if it submits the field as empty/null, `update()` writes null — which for `web_tool_policy` means *unrestricted*, silently removing a domain allow-list. → The upgrade notes say this in so many words: read the attribute explicitly when prefilling, or omit the key from the update payload (an omitted key keeps the stored value; a test pins that). Changing `update()` to treat blank as "keep" was considered and left out of scope, because it would remove the only way to clear a policy through the manager.
- **Hosts lose `api_key` in serialized payloads.** → Low cost: `update()` never accepted `api_key`, so no edit form can have depended on round-tripping it.
- **`makeVisible()` is per instance and easy to over-apply.** A host that calls it on a collection for convenience is back where it started. → README shows the narrow form (one attribute, one instance) and recommends reading the attribute instead.
- **The MySQL/PostgreSQL failure is reasoned, not reproduced.** No validating database is available in the test environment. → The fix is correct regardless of whether a given host ever hit the failure, since `text` is what the other two encrypted columns already use; the schema assertion guards the outcome.
- **Changing a column type rewrites the table on SQLite and takes a metadata lock on MySQL.** → `ai_systems` is a small configuration table; no mitigation needed.

## Migration Plan

1. Ship the migration and the `$hidden` change together in 0.17.0.
2. Hosts: `composer update`, re-publish migrations if they were published, `php artisan migrate`.
3. Hosts audit anything that serializes an `AiSystem` and reads one of the three keys from the result.

Rollback: downgrading the package restores the old serialization behavior. The column stays `text`, which the older code handles.

## Open Questions

- Whether a later change should offer a redacted, serializable view of `web_tool_policy` for admin screens. Deferrable: it would be additive, and nothing here forecloses it.
