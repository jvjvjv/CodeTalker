## Why

An `AiSystem` carries three secret-bearing attributes — `api_key`, `credentials`, and `web_tool_policy` (whose `credentials` map holds per-host auth headers). All three are encrypted at rest, but none is hidden, so `$system->toArray()`, a JSON response, or an Inertia prop built from the model carries every one of them decrypted. `AiMcpServer` already hides its `auth` for exactly this reason; `AiSystem` is the older model and never got the same treatment.

Separately, `credentials` is cast `encrypted:array` but its column is `json`. An encrypted value is an opaque string, not a JSON document, so the column type and the cast disagree: databases that validate JSON columns (MySQL, MariaDB, PostgreSQL) refuse the write. SQLite does not validate, which is why the package's own test suite never noticed. `api_key` and `web_tool_policy` are already `text`; `credentials` is the one column left behind.

## What Changes

- **BREAKING** `AiSystem` hides `api_key`, `credentials`, and `web_tool_policy` from array and JSON serialization. Attribute access (`$system->api_key`) is unchanged, so the package's own readers — provider configuration, web-tool scoping, duplication — are unaffected. A host that built an admin payload from the serialized model stops receiving these three keys and must read them explicitly, or opt in with `makeVisible()`.
- **BREAKING** A new migration changes `ai_systems.credentials` from `json` to `text`, so the column can hold the encrypted value the model has always tried to write. Hosts re-publish and run it.
- `web_tool_policy` is hidden whole, including its non-secret `allowed_domains` list. An edit screen that wants to show the allow-list reads it from the attribute.

Nothing about validation, the manager's write pipeline, or the stored shape of any of the three attributes changes.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `ai-management-services`: adds the requirement that a serialized system never carries its secrets, and that a system's credentials can be stored encrypted on every supported database.

## Impact

- **Code**: `AiSystem` gains `$hidden`. One new migration. No service changes.
- **Migrations**: `ai_systems.credentials` `json` → `text`. Existing values are carried across unchanged. The package loads its own migrations, so a plain `php artisan migrate` picks it up; hosts that published migrations re-publish first.
- **Host apps**: any controller, resource, or Inertia page that passes an `AiSystem` (or a persona/operator with its `aiSystem` relation loaded) through `toArray()`/`toJson()` and reads `api_key`, `credentials`, or `web_tool_policy` from the result. See design.md — a form that round-trips `web_tool_policy` and now submits it empty would clear the stored allow-list.
- **Docs**: README (`AiSystem` attribute table, Management Services notes, upgrade table) and CHANGELOG, as a 0.17.0 entry with both items under Breaking Changes.
- **Not in scope**: re-encrypting or repairing values written to `credentials` outside the model; changing how `update()` treats a blank `credentials` or `web_tool_policy`; hiding anything on other models.
