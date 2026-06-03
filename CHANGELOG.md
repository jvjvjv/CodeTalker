# Changelog

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
