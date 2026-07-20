# Changelog

## [0.7.0] — 2026-07-20

Memory extraction now runs once per conversation instead of once per assistant message, triggered by a new idle-completion command, and the admin memory rebuild no longer destroys the memories it cannot rebuild.

### Breaking Changes
- Memory extraction no longer runs after every assistant message. It fires once, when the new `ai:complete-idle-conversations` command marks a conversation `Completed` after `conversations.idle_timeout_minutes` (default 30) without a new message — so memories now appear up to roughly 45 minutes after a chat ends rather than immediately.
- Hosts that disable the package scheduler (`'schedule' => false`) must register `ai:complete-idle-conversations` themselves, or memory extraction will never run at all.
- Added the `conversations.idle_timeout_minutes` config key; re-publish or reconcile `config/code-talker.php`, since the package merges config shallowly.

### New Features
- Added `ai:complete-idle-conversations` (scheduled every 15 minutes), which marks inactive `Active` conversations `Completed` and thereby drives memory extraction. Supports `--minutes` to override the idle window and `--dry-run` to preview.
- Captured memory-extraction exchanges now record their `ai_conversation_id`, so memory calls can be correlated with the conversation they analyze when auditing token spend.

### Bug Fixes
- `AiMemoryService::rebuildMemories()` no longer deactivates every memory for a feature when no completed conversations exist to rebuild from; it leaves existing memories untouched and logs a warning instead. Because nothing previously set a conversation to `Completed`, the admin "Rebuild" action deleted all memories for the feature, rebuilt nothing, and reported success.
- `AiConversationObserver` now runs. No code path previously wrote `AiConversationStatus::Completed`, leaving the observer and its memory-extraction dispatch unreachable.
- Memory analysis skips conversations with no non-system messages rather than sending an empty transcript to the provider.

### Known Issues
- Reasoning tokens from `openai-compatible` providers (including LM Studio) may not surface as reasoning events or be included in reported usage, so logged token totals can under-report what the model actually generated.

## [0.6.0] — 2026-07-19

This release re-platforms the entire provider layer onto Laravel's first-party [laravel/ai](https://github.com/laravel/ai) SDK, deleting the five hand-rolled provider services and three vendor SDK dependencies while keeping the browser SSE wire format, database schema, routes, tool contracts, memory system, and admin endpoints unchanged.

### Breaking Changes
- Requires PHP `^8.3` (was `^8.2`) and Laravel `^12.62 || ^13.15`, driven by the new `laravel/ai` dependency.
- Removed `AiClientContract`, `CanLoadModels`, `AiClientFactory`, the `ExecutesAiTools` concern, and the five provider services (`ClaudeService`, `OpenAiService`, `GeminiService`, `GrokService`, `LmStudioService`); host code using them should migrate to `AgentFactory::forSystem()` / `forFeature()` (returns a laravel/ai agent) or use laravel/ai directly.
- Removed the `anthropic-ai/sdk`, `openai-php/client`, and `google-gemini-php/client` dependencies in favor of `laravel/ai`.
- Removed the unused `providers.*.api_key`, `providers.*.model`, and `providers.*.max_tokens` config fallbacks from `code-talker.php`; credentials and model settings come exclusively from `AiSystem` records (the `pricing`, `base_url`, `api_version`, and `server_url` keys remain).
- `AiLlmMessage` logging granularity changed: tool-use iterations are no longer separate request/response rows — each agent invocation logs one request and one response row whose `response_data.events` list now includes tool calls and tool results; `N.M` sub-turn numbering is only used for max-token continuations.

### New Features
- Chat streaming, the agentic tool loop, and provider normalization now run on the official laravel/ai SDK; `AiSystem` records are bridged to laravel/ai providers at runtime, so hosts do not need to publish or configure `config/ai.php`.
- Enabling `enable_thinking` on an Anthropic `AiSystem` now requests extended thinking (streamed to the browser as reasoning deltas).
- The `grok` provider now targets laravel/ai's `xai` driver, and `lm-studio` / `openai-compatible` systems run on the `openai-compatible` driver with per-system base URLs.

### Known Issues
- Whether LM Studio `reasoning_content` deltas surface as reasoning events depends on laravel/ai's `openai-compatible` driver; if unsupported, reasoning display for LM Studio degrades silently while text streaming is unaffected.

## [0.5.0] — 2026-06-19

This release re-platforms chatbot tools onto [laravel/mcp](https://github.com/laravel/mcp) so a single tool class runs in the local chat loop and can also be exposed to external MCP clients (Claude Desktop, Grok, etc.).

### Breaking Changes
- Tools are now [laravel/mcp](https://github.com/laravel/mcp) `Laravel\Mcp\Server\Tool` classes (`#[Description]`/`#[Name]` attributes, `schema(JsonSchema)`, `handle(Request): Response`); the `AiToolHandlerContract` interface is deprecated but still discovered and dispatched for one release to ease migration.
- Tools should depend on the new `Jvjvjv\CodeTalker\Support\ToolContext` for the current user/conversation instead of injecting `AiConversation` directly.
- The built-in tools were renamed from snake_case to kebab-case (`fetch_web_page` → `fetch-web-page`, `search_web` → `search-web`, `scan_memories` → `scan-memories`); run the published migration to remap persisted `AiSystem::allowed_tools` values.
- Added `laravel/mcp` as a dependency.

### New Features
- The built-in chatbot tools can now be exposed to external MCP clients through the bundled `CodeTalkerServer`, configurable (and disabled by default) under the new `code-talker.mcp` config key with web (HTTP, authenticated) and local (stdio) transports.
- The `scan-memories` tool is advertised by the external MCP server only to callers with a user identity (via `shouldRegister()`), so anonymous callers never see it.

## [0.4.1] — 2026-06-18

This patch release fixes PHP 8.2 and 8.3 compatibility for the `symfony/dom-crawler` dependency added in 0.4.0.

### Bug Fixes
- Widened the `symfony/dom-crawler` constraint to `^7.4 || ^8.1` so the package installs cleanly on PHP 8.2 and 8.3 (resolves v7.4.x) while still using the latest v8.x on PHP 8.4+.

## [0.4.0] — 2026-06-18

This release adds a multi-engine web search tool for chatbots, centralizes the package-owned scraper user agent, and fixes web-search execution paths.

### New Features
- Added `SearchWebTool` (`search_web`) to query Bing, Google, DuckDuckGo, and Brave, returning normalized results plus markdown links/snippets and pagination inputs for continued searching.
- Added `WebScraperUserAgent` support helper to centralize the package-owned JayScraper user agent string and reuse it across chatbot web tools.
- Added explicit API-versus-web execution paths for search providers (`*ViaApi` / `*ViaWeb`) to keep provider-specific transport logic separated and easier to maintain.

### Bug Fixes
- Updated chatbot web-fetch/search requests to use a single shared scraper user agent source instead of duplicating user-agent construction logic in multiple tools.
- Fixed the DuckDuckGo web search branch to use the web HTTP client path consistently after the API/web transport split.

## [0.3.0] — 2026-06-14

This release adds a web page fetching tool for chatbots and fixes the LM Studio provider payload.

### New Features
- Added `FetchWebPageTool` that fetches a URL and returns its readable text content, stripping scripts, styles, and markup and truncating to 20 000 characters.
- Exposed `ChatBotController` helper methods as `protected` so host applications can subclass the controller and override session, state, and routing behaviour.

### Bug Fixes
- Removed `enable_thinking` from the LM Studio chat payload; it is not a valid OpenAI-compatible field and was rejected by the endpoint.
- Added `ttl=600` to the LM Studio payload to keep the loaded model alive for 10 minutes between requests.

## [0.2.4] — 2026-06-10

This patch release removes dead admin controller payload data and fixes the package's admin tools controller inheritance so the class loads cleanly.

### Bug Fixes
- Removed the unused `navBlocks` payload and helper from the admin tools controller so the package no longer sends dead data to the Inertia page.
- Replaced the nonexistent `BaseAdminController` inheritance path in the admin tools controller so the package autoloads the controller cleanly.

## 0.2.3 — 2026-06-07

This release improves package route registration and adds a supported way for host applications to publish and customize the package route files.

### New Features
- Added publishable route stubs for the package's public chatbot and admin routes via `php artisan vendor:publish --tag=code-talker-routes`.
- Renamed the published route stub filenames to `routes/codetalker-chatbots.php` and `routes/codetalker-admin.php` so host-application overrides are clearer and less generic.
- Updated the package to prefer those published host-app route files automatically when they exist.

### Bug Fixes
- Deferred package route registration until the host application's service providers have finished booting so literal routes such as `/login` are registered before the package root-level chatbot wildcard routes.

## 0.2.2 - 2026-06-07

This patch release removes a stray package dependency and limits the default system prompt seed migration to only include the three generic prompts owned by the package. 

Host applications that previously relied on the default system prompt seed to create `TargetedResumeService`-specific prompts will need to create those prompts manually or through a custom migration after upgrading.

### Bug Fixes

- Removed the package's stray `TargetedResumeService` dependency from the default system prompt seed migration.
- Limited the default system prompt seed migration to the three package-owned generic prompts and moved their IDs into a dedicated package support class.

## 0.2.1 — 2026-06-07

This patch release fixes package migrations so host applications are no longer required to use UUID user keys.

### Bug Fixes
- Replaced hardcoded UUID user foreign keys in the package migrations with model-aware user key definitions based on `code-talker.user_model`.
- Removed follow-up migration steps that attempted to retype `user_id` columns as UUIDs after the initial tables were created.
- Changed memory user scoping storage to use a generic string identifier so integer, UUID, ULID, and custom string user keys are all supported.

## 0.2.0 — 2026-06-07

This breaking release removes package-managed chatbot authentication and authorization so the package only manages AI chatbot behavior and the consuming application owns all access rules.

### Breaking Changes
- Removed package-level chatbot visibility and role concepts. `AiChatBot::is_public`, any previous `allowed_roles` usage, and the package's private-bot authorization hook are no longer supported.
- The package no longer decides who may access `/chats`, `/chat/{slug}`, `/{slug}`, or `/admin/ai/*`. Host applications must enforce all chatbot and admin access through their own middleware, gates, and policies.
- Fresh installs no longer create the `ai_chat_bots.is_public` column, and upgraded installs must run the new migration that drops it.

### New Features
- Simplified the package boundary so chatbot management stays in the package while authentication and authorization live entirely in the consuming application.

### Bug Fixes
- Removed internal access filtering that mixed package chatbot behavior with host-application authorization decisions.

### Known Issues
- If you previously relied on package-managed bot visibility, move that logic into your application's `code-talker.middleware` and `code-talker.admin_middleware` configuration before upgrading. Public bot access should now be expressed by leaving chatbot routes open, and restricted bot access should be expressed with your application's own auth middleware, gates, or policies.

## 0.1.2 — 2026-06-06

This patch release updates the package's Inertia Laravel dependency line to keep host-application installs aligned with the current compatibility targets.

### Bug Fixes
- Updated the `inertiajs/inertia-laravel` dependency constraints and lockfile so host applications resolve the intended package version consistently.

## 0.1.1 — 2026-06-06

This patch release improves package compatibility and installation behavior for host Laravel applications, including verified Laravel 13 support.

### Bug Fixes
- Expanded the package constraints to support Laravel 13 and the matching Orchestra Testbench release line for package testing.
- Declared `inertiajs/inertia-laravel` as a runtime dependency so package controllers rendering Inertia responses install cleanly in host applications.
- Replaced the package-root test script with a direct PHPUnit invocation and added smoke coverage for package bootstrapping, route registration, and service bindings.
- Added a GitHub Actions workflow that validates the package and runs the test suite on PHP 8.3 and PHP 8.4.
- Documented the admin authorization expectations and suggested `bspdx/keystone` as a host-app option for the surrounding access-control layer.

### Known Issues
- This package is currently locked into using Inertia. This will be removed in a future version and will be a breaking change, so plan host-app integrations accordingly.

## 0.1.0 — 2026-06-04

This is the initial release, pulled from from the original application code for [Jason Vertucio](https://jasonvertucio.com). The package provides a comprehensive AI chatbot framework with support for multiple LLM providers, agentic tool use, memory management, and conversation tracking. It is designed to be highly extensible and customizable for a wide range of applications.

### New Features
- Multi-provider AI client abstraction supporting Anthropic (Claude), OpenAI, Google Gemini, xAI Grok, and LM Studio through a unified `AiClientContract` interface.
- `AiSystem` database-driven configuration model for storing provider credentials, model selection, temperature, context length, and capability flags per endpoint.
- `AiChatBot` model for defining named chatbot personas with customizable prompt templates, slugs, role-based access control, and optional visitor identity collection.
- Streaming chat response delivery via Server-Sent Events with an agentic tool-use loop (up to 6 iterations) that handles `max_tokens` continuation automatically.
- MCP-style tool registration: implement `AiToolHandlerContract` and register host-app tool directories via `CodeTalkerServiceProvider::addToolDirectory()`.
- Per-user and per-visitor memory system that extracts reusable insights from completed conversations and injects them into future system prompts via `AiFeatureMemory`.
- `AiSystemPrompt` model for managing reusable system prompt templates that can be assigned to `AiSystem` records.
- `AiSystemFeatureDefault` for mapping feature keys to default `AiSystem` records, enabling `AiClientFactory::forFeature()` lookups.
- Conversation usage tracking: token counts, per-model pricing snapshots, and aggregated cost stored on `AiConversation`.
- Full `AiLlmMessage` request/response logging for every LLM turn, including tool-use iterations.
- Admin route group at `/admin/ai/*` covering CRUD for systems, system prompts, chat bots, conversations, and memories (requires `can:manage-ai-tools`).
- Public chat routes auto-registered at `/chat/{slug}` or `/{slug}` (root access path), including shareable hash-based conversation URLs at `/chat/{slug}/{hash}`.
- Browser-side conversation state persisted via session and a 180-day cookie, supporting multi-conversation history per bot per browser.
- Artisan commands: `ai:backfill-system-capabilities`, `ai:backfill-conversation-usage`, `ai:sync-conversation-usage`.
- Scheduled jobs for conversation usage sync (twice daily) and backfill (daily at 02:30), disableable via `'schedule' => false` in config.
- Model factory resolution delegates to `Database\Factories\` in the host application, keeping the package itself factory-free.
