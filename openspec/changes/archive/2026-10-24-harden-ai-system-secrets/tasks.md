## 1. Hide secrets on AiSystem

- [x] 1.1 Add a failing test in `tests/Feature/AiSystemManagerTest.php` asserting that a system created with an API key, `credentials`, and a `web_tool_policy` has none of those three keys in `toArray()` or in `json_decode($system->toJson(), true)`, while `name` and `provider` are still present; run it and confirm it fails on the three keys
- [x] 1.2 Add `protected $hidden = ['api_key', 'credentials', 'web_tool_policy'];` to `AiSystem`, with a comment in the style of `AiMcpServer`'s; verify the test from 1.1 passes
- [x] 1.3 Add a test that attribute access still returns decrypted values (`api_key` string, `credentials` and `web_tool_policy` arrays) from a `fresh()` instance; verify it passes
- [x] 1.4 Add a test that `makeVisible('web_tool_policy')` exposes that key in `toArray()` and leaves `api_key` and `credentials` absent; verify it passes
- [x] 1.5 Add a test that an `AiPersona` serialized with `aiSystem` loaded has none of the three keys under `ai_system`; verify it passes
- [x] 1.6 Add a test that `AiSystemManager::duplicate()` yields a copy whose `api_key`, `credentials`, and `web_tool_policy` equal the original's; verify it passes
- [x] 1.7 Add a test that `AiSystemManager::update()` with no `credentials` or `web_tool_policy` key leaves both stored values unchanged; verify it passes

## 2. Store credentials in a text column

- [x] 2.1 Add a failing test asserting the migration, run in pretend mode against a MySQL connection, emits ``alter table `ai_systems` modify `credentials` text null``; run it and confirm it fails because the migration does not exist. (Revised during implementation: `Schema::getColumnType()` reports `text` for a `json` column on SQLite, so the originally planned assertion could never fail.)
- [x] 2.2 Add migration `2026_10_12_000001_change_ai_systems_credentials_to_text.php` whose `up()` runs `$table->text('credentials')->nullable()->change()` and whose `down()` is a no-op with a comment explaining why (see design.md); verify the test from 2.1 passes
- [x] 2.3 Add a round-trip test: save a system with a credentials map, assert the raw `ai_systems.credentials` value read through `DB::table` is a non-null string that is not the JSON encoding of the map, and that `fresh()->credentials` equals the map; verify it passes
- [x] 2.4 Add a test that a system saved without credentials stores and reads null; verify it passes
- [x] 2.5 Add a test that a credentials value present before the migration is readable after it: put the column back to `json`, write a system, run the migration's `up()`, assert `fresh()->credentials` is unchanged; verify it passes

## 3. Documentation

- [x] 3.1 README `AiSystem` attribute table: add a `credentials` row and note on `api_key`, `credentials`, and `web_tool_policy` that they are stored encrypted and omitted from serialized output; verify by reading the rendered table
- [x] 3.2 README Management Services notes: state that the three attributes are read by attribute access, show the narrow `makeVisible()` form, and warn that prefilling an edit form must read `web_tool_policy` explicitly because submitting it empty clears the allow-list; verify the paragraph describes shipped behavior only, with no reference to this plan
- [x] 3.3 README "Migrating between versions" table: add a 0.17.0 row covering the migration and the serialization change; verify it sits above 0.16.1
- [x] 3.4 CHANGELOG: add a `[0.17.0]` entry at the top with both items under Breaking Changes, worded as behavior a host must react to and carrying no threat narrative; verify it follows the format in CLAUDE.md
- [x] 3.5 Update the local CLAUDE.md Management Services invariants to record that `AiSystem` hides the three attributes and that readers must use attribute access; verify the note is present

## 4. Verification

- [x] 4.1 Run `composer test` and confirm the whole suite passes with no new warnings
- [x] 4.2 Run `openspec validate harden-ai-system-secrets` and confirm it reports the change as valid
