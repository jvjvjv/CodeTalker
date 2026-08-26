## 1. Spike (done — see design.md)

- [x] 1.1 Determine whether `RememberConversation` can own persistence. **It cannot**: its `then()` callback fires only after the stream generator is fully consumed, and `ConversationTurnRunner` breaks out of that loop on client abort and max-duration. Both paths would persist nothing, silently regressing the 0.9.0 partial-output fix
- [x] 1.2 Confirm `StreamedAgentResponse` carries text/usage/toolCalls/toolResults — it does, extending `AgentResponse`; the earlier concern was unfounded
- [x] 1.3 Confirm reasoning has no representation in the contract — correct, `TextDelta::combine()` is text only
- [x] 1.4 Decision: adopt the read path; `TurnRecorder` stays the writer

## 2. Schema

- [x] 2.1 Migration adding `user_id`, `agent`, `attachments`, `tool_calls`, `tool_results`, and `usage` to `ai_conversation_messages`, all nullable so existing rows stay valid
- [x] 2.2 Add the new columns to `AiConversationMessage::$fillable` and cast the four JSON ones to arrays

## 3. The store

- [x] 3.1 `Services/Conversation/CodeTalkerConversationStore implements ConversationStore`, all five methods
- [x] 3.2 `getLatestConversationMessages()` — rebuild `UserMessage` with attachments, `AssistantMessage` with tool calls, and `ToolResultMessage`, oldest-first, limited
- [x] 3.3 **Exclude `system`-role rows from replayed history** — they are instructions, not turns. Upstream has no equivalent, so this is the store's own rule
- [x] 3.4 Skip assistant rows with neither content nor tool calls; providers reject empty assistant messages
- [x] 3.5 Conversation ids are the package's integer keys cast to string; the contract only round-trips them
- [x] 3.6 Bind it over `ConversationStore::class` in `CodeTalkerServiceProvider::register()`

## 4. The agent

- [x] 4.1 `CodeTalkerAgent` uses `RemembersConversations` and implements the contract
- [x] 4.2 Alias the trait's `messages()` and override it to return stored history followed by appended in-turn messages — a class method silently wins over a trait method, so this must be deliberate
- [x] 4.3 Add `withStoredConversation(string $id)`, setting the conversation id **without** a participant, so history replays without arming the write middleware
- [x] 4.4 `AgentFactory` passes the conversation through when one is available

## 5. Replacing the transcript builder

- [x] 5.1 Move the system-prompt read out of `TranscriptBuilder` — it is unrelated to history reconstruction
- [x] 5.2 Point `AiChatBotConversationService` at the store for history and the new reader for the system prompt
- [x] 5.3 Delete `TranscriptBuilder` and `ConversationTranscript`
- [x] 5.4 `TurnRecorder` also persists `tool_calls`, `tool_results`, and `usage`, so its rows are replayable

## 6. Tests

- [x] 6.1 Store tests: round-tripping a conversation, history ordering and limit, system-role exclusion, empty-assistant skipping
- [x] 6.2 Tool-call reconstruction — an assistant row with tool calls and results yields an assistant message plus a tool-result message
- [x] 6.3 Attachment reconstruction — a user row with attachments yields a `UserMessage` carrying them
- [x] 6.4 Agent test: stored history precedes appended messages
- [x] 6.5 Assert the container binding resolves to the package store
- [x] 6.6 Assert no participant is attached on the package's own path, so nothing is written twice
- [x] 6.7 `AiChatBotConversationServiceTest` must still pass unchanged — it is the characterization safety net this change is sequenced to keep

## 7. Documentation

- [x] 7.1 README: resuming a conversation, and the warning that a host attaching a participant while also using the chat service gets two writers
- [x] 7.2 Note `ai.conversations.generate_title` for hosts using `continue()` directly, since upstream's title generation costs a provider call
- [x] 7.3 `CLAUDE.md`: the store, the read/write split and why, and the system-role exclusion
- [x] 7.4 Run `composer test`
