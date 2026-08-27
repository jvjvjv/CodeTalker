## 1. Backend: emit `page_reload`

- [x] 1.1 In `ConversationTurnRunner`'s `ToolResultEvent` branch, `json_decode($event->toolResult->result, true)` and check `($decoded['_page_reload'] ?? false) === true`
- [x] 1.2 When true, yield `['type' => 'page_reload']` alongside the existing `$recordedToolResults[]` write (and the existing `tool_use_progress` yield when `$includeToolPayloads` is on)
- [x] 1.3 Add a test case: a tool result carrying `_page_reload: true` produces a `page_reload` frame; a result without the key does not. Added to `AiChatBotConversationServiceTest` (there is no dedicated `ConversationTurnRunnerTest`) via a new fixture tool, `tests/Fixtures/Tools/PageReloadingTestTool.php`, exercised through the real tool-dispatch path

## 2. TypeScript declarations

- [x] 2.1 Add `ToolUseProgressEvent` to `resources/js/types/code-talker.d.ts`: `type: 'tool_use_progress'`, `text: string`, `tools: string[]`, optional `input?: unknown`, `output?: unknown`, `successful?: boolean`
- [x] 2.2 Add `PageReloadEvent`: `type: 'page_reload'`, no other fields
- [x] 2.3 Add both to the `ChatStreamEvent` union
- [x] 2.4 Run `npm run typecheck`

## 3. Published client

- [x] 3.1 Add `onToolProgress?: (event: { text: string; tools: string[]; input?: unknown; output?: unknown; successful?: boolean }) => void` and `onPageReload?: () => void` to `ChatTurnCallbacks` in `resources/js/code-talker-stream.ts`
- [x] 3.2 Add `case 'tool_use_progress'` and `case 'page_reload'` to `dispatch()`, calling the respective callback and returning `false`
- [x] 3.3 Run `npm run typecheck`

## 4. Documentation

- [x] 4.1 Add `tool_use_progress` (payload, including the optional debug fields) and `page_reload` to the README's turn-events table under "Turn events"
- [x] 4.2 Document the `_page_reload` convention under Tool Registration or Frontend Integration: a tool signals a reload by returning `_page_reload: true` in its structured result
- [x] 4.3 Add a CHANGELOG entry (New Features: `page_reload` event + `onPageReload`/`onToolProgress` client callbacks; Bug Fixes: `tool_use_progress` is now in the published type declarations and no longer silently dropped by the published client)

## 5. Verify

- [x] 5.1 `composer test`
- [x] 5.2 `npm run typecheck`
