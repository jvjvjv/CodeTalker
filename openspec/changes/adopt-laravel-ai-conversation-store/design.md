# Design — Adopting laravel/ai's ConversationStore

## Spike outcome: the write path cannot be adopted

The proposal planned for the store to own persistence, with `TurnRecorder` enriching the row it wrote, and flagged that this needed verifying first. It does not survive contact with the streaming path.

**What was verified**, reading `StreamsText`, `StreamableAgentResponse`, `RememberConversation`, and `ConversationTurnRunner`:

1. `RememberConversation` registers its work as a `then()` callback on the `StreamableAgentResponse` the provider returns.
2. `StreamableAgentResponse::getIterator()` invokes `thenCallbacks` **only after the generator is fully consumed** — the callbacks run at the end of the `foreach`, not on each event.
3. `ConversationTurnRunner` deliberately `break`s out of that `foreach` in two cases: a client abort, and the max-stream-duration guard.

So on either abort path the iterator never completes, the `then()` callbacks never fire, and **nothing is persisted at all**. That is a direct regression of the 0.9.0 fix which made a timed-out turn keep whatever it had produced — including a reasoning-only turn from a model that deliberated without ever answering. Worse, it regresses silently: the turn simply vanishes.

Two smaller findings point the same way:

- `StreamedAgentResponse` extends `AgentResponse` and does carry `text`, `usage`, `toolCalls`, `toolResults`, and `meta` — so the earlier concern that streaming produced an incompatible response was unfounded. But its text comes from `TextDelta::combine($events)`, which is **text only**. Reasoning deltas are separate events and have no representation in the store contract, so `reasoning_content` and `blocks` would be dropped.
- Write ownership by the middleware requires a conversation participant (`hasConversationParticipant()` gates the middleware), and anonymous visitors have no user object.

**Decision: adopt the read path, keep `TurnRecorder` as the writer.** This is the fallback the proposal named. The store is implemented in full — all five contract methods — so a host that wants upstream's write behavior can opt into it with its own agent, but the package's own turn does not attach a participant and therefore never double-writes.

## What this still buys

The read path was always the larger prize, and it is unaffected:

- `getLatestConversationMessages()` reconstructs `UserMessage` with attachments, `AssistantMessage` with tool calls, and `ToolResultMessage` — none of which `TranscriptBuilder` can express. It rebuilds history as real message objects rather than bare text pairs.
- This is what makes attachment replay possible, which is the blocker for the planned vision work.
- `CodeTalkerAgent` gains `continue()` and `continueLastConversation()` for hosts driving agents directly.

## Shape

**Migration** adds to `ai_conversation_messages`: `user_id`, `agent`, `attachments`, `tool_calls`, `tool_results`, `usage`. `metadata` already exists and carries what upstream calls `meta`.

**`Services/Conversation/CodeTalkerConversationStore`** implements `ConversationStore` over `AiConversation` / `AiConversationMessage`, bound over the upstream singleton in `CodeTalkerServiceProvider`. Two deviations from `DatabaseConversationStore` are deliberate:

- Conversation ids are the package's auto-increment integers cast to string, not uuid7. The contract only round-trips the value, so this is invisible to callers.
- **`system`-role messages are excluded from replayed history.** They hold the generated system prompt, which is passed to the agent as instructions, not as a turn. Upstream has no system role to contend with; this store does.

**`CodeTalkerAgent`** uses the `RemembersConversations` trait, aliasing its `messages()` so the class can combine stored history with the in-turn messages `append()` adds for a "Continue." reprompt:

```php
use RemembersConversations { messages as storedMessages; }

public function messages(): iterable
{
    return [...$this->storedMessages(), ...$this->messages];
}
```

It also gains `withStoredConversation(string $id)`, which sets the conversation id **without** a participant — history replay without triggering the write middleware. That method is the seam that keeps the package on the read path while leaving `continue()` available to hosts.

**`TranscriptBuilder` and `ConversationTranscript` are deleted.** The system-prompt read they also performed moves to a one-line query, since it is unrelated to history reconstruction.

**`TurnRecorder`** additionally persists `tool_calls`, `tool_results`, and `usage`, so the rows it writes are replayable by the store. Without this the store would read back history missing exactly the structure it exists to preserve.

## Risks accepted

- **Two writers exist in principle.** A host that calls `continue($id, $user)` *and* drives `AiChatBotConversationService` would get both the middleware and `TurnRecorder` writing. Documented rather than prevented — the package's own path never does it, and forbidding it would mean overriding upstream behavior a host explicitly asked for.
- **The store trusts `metadata` for meta.** Upstream stores `meta` as its own column; reusing `metadata` avoids a redundant column but means a host reading raw rows sees a differently-shaped payload than upstream's schema.
