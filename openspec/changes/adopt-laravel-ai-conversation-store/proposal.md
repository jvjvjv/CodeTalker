## Why

Target release: **0.12.0**. Second of three staged changes.

`laravel/ai` ships a conversation-memory system this package reimplements badly. `Laravel\Ai\Contracts\ConversationStore` is bound as a **container singleton** in `AiServiceProvider`, so any package can replace it:

```php
$this->app->singleton(ConversationStore::class, fn () => new DatabaseConversationStore(...));
```

Rebinding it to a Code Talker implementation gives agents `continue($conversationId, $as)` and `continueLastConversation($as)` natively, and — more valuable — replaces `TranscriptBuilder` with upstream's `getLatestConversationMessages()`, which reconstructs **tool calls, tool results, and attachments** as real `Message` objects.

That last point is the actual prize. `TranscriptBuilder` today rebuilds history as bare `UserMessage`/`AssistantMessage` pairs from a `content` string. It cannot replay a tool call, and it cannot carry an image — which is precisely why the planned vision work has nowhere to put attachments. Adopting the store unblocks that change rather than competing with it.

Verified: the `RememberConversation` middleware is attached in `gatherMiddlewareFor()`, which is called by **both** `GeneratesText::prompt()` and `StreamsText::stream()`, so this applies to the streaming path the chat turn actually uses.

## What Changes

- **`Services/Conversation/CodeTalkerConversationStore`** implements `ConversationStore` over the existing `ai_conversations` / `ai_conversation_messages` tables, and is bound over the upstream singleton in `CodeTalkerServiceProvider`.
- **`CodeTalkerAgent`** uses the `RemembersConversations` trait and implements the matching contract. Its existing `messages()` and `append()` need deliberate reconciliation — the trait supplies its own `messages()` that reads from the store, and a class method silently wins over a trait method.
- **A migration** adds the columns the contract needs on `ai_conversation_messages`: `attachments`, `tool_calls`, `tool_results`, `usage`, and `agent`. The table currently has only `role`, `content`, `reasoning_content`, `blocks`, `metadata`, `created_at`.
- **`TranscriptBuilder` and `ConversationTranscript` are deleted.** History replay comes from the store.
- **`TurnRecorder` is refactored** to enrich the message row the store writes, rather than creating its own.

## Risks

Four, in descending order of how much they could derail the change.

**1. `AgentResponse` has no reasoning field.** It carries `text`, `usage`, and `meta` only. `TurnRecorder` currently persists `reasoning_content` and `blocks`, and reasoning streaming for openai-compatible providers was a deliberate 0.8.0 bug fix. If the store's `storeAssistantMessage()` becomes the sole writer, reasoning is lost — a silent regression against a shipped feature. Mitigation: the store records the id of the row it just wrote, and `TurnRecorder` enriches that row with `reasoning_content` and `blocks` instead of inserting its own. **This needs a spike before the rest of the change is planned in detail** — if the enrichment point turns out not to exist, the write path stays with `TurnRecorder` and only the read path is adopted.

**2. Double writes.** The `RememberConversation` middleware writes both the user and assistant message. `AiChatBotConversationService` and `TurnRecorder` also write them. Without care every turn is persisted twice.

**3. Anonymous visitors.** The middleware only attaches when `hasConversationParticipant()` is true, i.e. `conversationUser !== null`, and `continueLastConversation()` reads `$as->id`. Public unauthenticated chat is this package's whole differentiator, so visitors need a lightweight participant object exposing an `id` — likely derived from `visitor_email`. Confirm the middleware tolerates it before committing.

**4. Title generation costs a provider call.** `RememberConversation::generateTitle()` calls `cheapestTextModel()` unless `ai.conversations.generate_title` is false. Code Talker already has `ConversationTitle::fromUserMessage()`, which makes no call. The config must be set, or every new conversation silently pays for an extra request.

## Capabilities

### New Capabilities

- `native-conversation-continuation`: agents resume a stored conversation through laravel/ai's own `continue()` API, with history — including tool calls, tool results, and attachments — replayed by the store rather than rebuilt from message text.

### Modified Capabilities

- Conversation persistence moves from `TurnRecorder` owning the write to the store owning it, with `TurnRecorder` enriching. The `ai_conversation_messages` schema grows.

## Impact

- **Code**: new `Services/Conversation/CodeTalkerConversationStore`; `CodeTalkerAgent`; `CodeTalkerServiceProvider` binding; one migration; `TurnRecorder`; deletion of `TranscriptBuilder` and `ConversationTranscript`.
- **Sequencing**: deliberately **before** the HTTP removal, not after. `AiChatBotConversationServiceTest` (643 lines) asserts the exact SSE frame sequence a turn emits, which is the only end-to-end characterization of turn behavior in the suite. Doing the store swap while that test still exists means any behavioral drift surfaces immediately; doing it afterwards would mean rewriting the safety net and the thing it guards in the same release.
- **Unblocks**: message attachments and vision. Attachment replay is currently impossible, and this is what makes it possible.
- **Host apps**: breaking if they read `ai_conversation_messages` directly or call `TranscriptBuilder`.
- **Version**: `0.12.0`.
