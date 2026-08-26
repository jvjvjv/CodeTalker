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
- **`TurnRecorder` keeps ownership of writes** and additionally persists tool calls, tool results, and usage so its rows are replayable. See `design.md` — the spike ruled out middleware-driven persistence.

## Risks

Four, in descending order of how much they could derail the change.

**1. ~~The write path~~ — RESOLVED BY SPIKE; see `design.md`.** Middleware-driven persistence is not viable: `RememberConversation` runs as a `then()` callback that fires only after the stream generator is fully consumed, and `ConversationTurnRunner` deliberately breaks out of that loop on client abort and on the max-duration guard. On either path nothing would be persisted at all — silently regressing the 0.9.0 fix that keeps partial output from a timed-out turn. The read path is adopted; `TurnRecorder` remains the writer.

**2. Double writes.** Avoided by construction: the package sets the conversation id without a participant, and the middleware only attaches when a participant is present. A host that opts into `continue($id, $user)` while also driving the chat service would get both writers — documented, not prevented.

**3. Anonymous visitors.** Moot for the package's own path, which never sets a participant. Still relevant to hosts calling `continue()` directly, where an object exposing an `id` is required.

**4. Title generation costs a provider call.** Only reachable through the write middleware, so it does not affect the package's path. Documented for hosts using `continue()`: set `ai.conversations.generate_title` to false, or every new conversation silently pays for an extra request on top of `ConversationTitle::fromUserMessage()`.

## Capabilities

### New Capabilities

- `native-conversation-continuation`: agents resume a stored conversation through laravel/ai's own `continue()` API, with history — including tool calls, tool results, and attachments — replayed by the store rather than rebuilt from message text.

### Modified Capabilities

- History replay moves from `TranscriptBuilder` to the store, gaining tool calls, tool results, and attachments. Writes stay with `TurnRecorder`, which now also persists that structure so its rows are replayable. The `ai_conversation_messages` schema grows.

## Impact

- **Code**: new `Services/Conversation/CodeTalkerConversationStore`; `CodeTalkerAgent`; `CodeTalkerServiceProvider` binding; one migration; `TurnRecorder`; deletion of `TranscriptBuilder` and `ConversationTranscript`.
- **Sequencing**: deliberately **before** the HTTP removal, not after. `AiChatBotConversationServiceTest` (643 lines) asserts the exact SSE frame sequence a turn emits, which is the only end-to-end characterization of turn behavior in the suite. Doing the store swap while that test still exists means any behavioral drift surfaces immediately; doing it afterwards would mean rewriting the safety net and the thing it guards in the same release.
- **Unblocks**: message attachments and vision. Attachment replay is currently impossible, and this is what makes it possible.
- **Host apps**: breaking if they read `ai_conversation_messages` directly or call `TranscriptBuilder`.
- **Version**: `0.12.0`.
