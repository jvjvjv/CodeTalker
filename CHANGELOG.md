# Changelog

## 0.2.1 — 2026-06-06

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
