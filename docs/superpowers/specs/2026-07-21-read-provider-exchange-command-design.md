# Design: `ai:read-exchange` CLI command

**Date:** 2026-07-21
**Status:** Approved

## Purpose

Give a CLI user (without direct database access) a way to read the raw
request/response captured in the `ai_provider_exchanges` table. Today the table
only records LM Studio traffic (OpenAI-compatible SSE), which keeps parsing
tractable. The required input is a single `ai_llm_message_id`, but because a CLI
user rarely knows that id, the command provides an interactive drilldown that
produces it.

## Command

```
php artisan ai:read-exchange {ai_llm_message_id?}
```

- Positional `ai_llm_message_id` is optional.
- When provided, the command jumps straight to display.
- When omitted, an interactive drilldown resolves it.

Registered in `CodeTalkerServiceProvider` alongside the other package commands.

## Interactive Drilldown (argument omitted)

Uses Laravel's `choice()` at each step. Lists **everything** — no filtering by
whether exchanges exist for a given item.

1. **ChatBot** — all `AiChatBot` rows labeled `name (id)`. Plus an
   `[unassigned]` option covering conversations whose `ai_chat_bot_id` is null.
2. **Conversation** — the selected bot's conversations, labeled
   `title (id · created_at)`, newest first. Under `[unassigned]`, list
   conversations with a null `ai_chat_bot_id`.
3. **Message** — the selected conversation's `AiLlmMessage` rows, labeled
   `#turn_number direction (id · created_at)`. The selected row's `id` becomes
   the `ai_llm_message_id`.

## Exchange Gathering

Given `ai_llm_message_id = X`:

1. Fetch all `AiProviderExchange` rows where `ai_llm_message_id = X`, ordered by
   `id`.
2. **Orphan trailing rows.** Starting immediately after the highest matched
   `id`, walk consecutive rows in global `id` order and include any row that has
   **both** `ai_llm_message_id` null **and** `ai_conversation_id` null. Stop at
   the first row that has either column set. These orphan rows belong to the
   selected exchange (a provider call recorded without message/conversation
   linkage tends to relate to the row above it).

If no exchange rows are found for `X`, the command reports that clearly and
exits non-zero.

## Per-Exchange Display

For each gathered row, print a block containing:

- **System** — `exchange.ai_system_id → AiSystem.name`.
- **Model** — `exchange.model`.
- **ChatBot** — `exchange.ai_conversation_id → AiConversation → AiChatBot.name`.
- **Conversation title** — via that conversation.
- **Request (text)** — `request_body` JSON-decoded; render each `messages[]`
  entry as `role: content` (system / user / assistant / tool). Fall back to
  pretty-printed JSON when the shape is unexpected or decoding fails.
- **Response (text)** — from the **sibling response** `AiLlmMessage`: the row
  with the same `ai_conversation_id` and `turn_number`, `direction = 'response'`,
  and `id > X`, earliest such row. Concatenate `response_data.events[].delta`
  where `type = 'text_delta'`. Reasoning (`type = 'reasoning_delta'`) is shown in
  a separate labeled section.
- **Raw response (parsed)** — parse `exchange.raw_response` SSE. For each
  `data: {…}` line (ignoring `data: [DONE]`), collect
  `choices[0].delta.content` (streaming) or `choices[0].message.content`
  (non-streaming) into the response text, and `choices[0].delta.reasoning_content`
  into a separate **Reasoning** section.

### Linkage note

An exchange's `ai_llm_message_id` points at the **request** `AiLlmMessage` row,
which is created *before* streaming and therefore has no `response_data`. The
"Response (text)" field is therefore sourced from the **sibling response** row,
not the linked row. (Confirmed with the user.)

Orphan rows have no `ai_conversation_id` and thus no conversation, chatbot, or
sibling-response row. For those, System/Model plus Request/Raw response are
shown; unavailable fields render as `—`.

## Code Structure

- **`src/Console/Commands/ReadProviderExchangeCommand.php`** — argument handling,
  interactive drilldown, exchange gathering, orchestration, and output.
- **`src/Services/RawExchange/ExchangeTranscriptParser.php`** — pure, testable
  parsing with no framework or DB dependencies:
  - `requestText(?string $requestBody): string`
  - `sseResponse(?string $rawResponse): array{text: string, reasoning: string}`
  - `llmResponse(?array $responseData): array{text: string, reasoning: string}`

  Keeps fragile parsing out of the command and independently unit-testable.
- Register the command in `CodeTalkerServiceProvider::boot()`'s `$this->commands([...])`
  list.

## Testing

- **Unit — `ExchangeTranscriptParser`:**
  - Streaming SSE with multiple `data:` lines → concatenated content.
  - Non-streaming single JSON body → `choices[0].message.content`.
  - `reasoning_content` deltas collected into the reasoning section separately.
  - Malformed / empty / null input handled gracefully (no throw, empty string).
  - `llmResponse` extracts `text_delta` vs `reasoning_delta` from stored events.
- **Feature — command:**
  - Seed an `AiSystem`, `AiChatBot`, `AiConversation`, a request `AiLlmMessage`,
    a sibling response `AiLlmMessage`, an exchange row linked to the request
    message, and a trailing orphan exchange row.
  - Run with the `ai_llm_message_id` argument.
  - Assert output contains system name, model, chatbot name, conversation title,
    request text, response text, and that the orphan row is also rendered.

## Out of Scope

- Non-LM-Studio providers (Anthropic/OpenAI native shapes) — the parser targets
  the OpenAI-compatible SSE that LM Studio emits, matching current capture.
- Editing, exporting, or deleting exchanges.
