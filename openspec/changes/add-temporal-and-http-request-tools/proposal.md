## Why

Two capability gaps keep turning up in chat-bot conversations that the package cannot answer today.

**The model does not know what time it is.** An `AiSystem` prompt is static and a provider's model has a training cutoff, so anything date-relative — "this week", "how long until", "is that expired" — is answered from a stale guess. There is no tool that reports the wall clock, and no way to answer for the visitor's zone rather than the server's.

**`fetch-web-page` only reads HTML pages.** It is `GET`-only and rejects every content type but `text/html`, `application/xhtml+xml`, and `text/plain` (`FetchWebPageTool::isHtmlResponse()`, and the `Response::error('The URL did not return an HTML or plain text page.')` branch). A bot that needs to call a JSON API, read an XML feed, or `POST` a form has no path at all — the host has to write a bespoke tool and register it through `addToolDirectory()`, re-solving fetching, timeouts, size caps, error shaping, and user-agent identification each time.

## What Changes

- **New `get-temporal-information` tool.** Returns the current date and time. Accepts an optional IANA timezone (`America/New_York`) or fixed UTC offset (`-05:00`, `+0530`) and answers in that zone; falls back to the application timezone when none is given. Returns a structured payload — ISO-8601 instant, the resolved zone and its offset, and the pre-formatted human parts (weekday, date, time) — so the model does not have to do calendar arithmetic on a string.

- **New `http-request` tool.** A general HTTP client for the agent: `GET` / `POST` / `PUT` / `PATCH` / `DELETE`, an optional request body, and response decoding for JSON, XML, plain text, and HTML. JSON responses come back as a decoded structure rather than a string. HTML responses reuse the existing readable-text extraction, including `keep_html` and `target_selector`.

- **The model must declare its own request policy, and the tool fails closed without one.** `http-request` takes a required policy input naming the methods it intends to use and whether private/loopback hosts are permitted. Absent or empty, the request is refused with an error that tells the model what to declare; a request outside the declared policy is refused against the policy it declared. This makes intent explicit in the transcript and in the `AiLlmMessage` log, and stops accidental reach into the host's internal services. It is **not** a defence against an adversarial or prompt-injected model, which can simply declare a permissive policy — see `design.md`.

- **The model never supplies credentials.** Request headers set by the model are filtered: hop-by-hop and authentication headers are stripped. Credentials are attached by the package from a host-configured per-host header map under `code-talker.tools.http_request.credentials`, so a token is never visible to, or inventable by, the model.

- **`FetchWebPageTool`'s fetching and extraction move into a shared collaborator serving both tools.** URL validation, the browser-like header set and `WebScraperUserAgent` identification, connect/read timeouts, the 150 KB body cap and 20 KB content truncation, HTML readable-text extraction, `<title>` extraction, `target_selector` scoping, and whitespace normalization stop living inside `FetchWebPageTool` and become one reusable unit.

- **`fetch-web-page` keeps its exact MCP contract.** Same `#[Name]`, same four inputs, same structured response keys, same error strings. It becomes a thin façade over the shared collaborator. This is **not** a breaking change and no `allowed_tools` migration is needed.

- **Both new tools are registered on the external MCP server** (`CodeTalkerServer`), alongside `fetch-web-page`, `search-web`, and `scan-memories`, with `http-request` fully exposed including its write methods. That transport is authenticated (`auth:sanctum` by default) and is off unless `code-talker.mcp.enabled` is set.

## Capabilities

### New Capabilities
- `temporal-information-tool`: what the agent may ask about the current date and time, how a timezone or UTC offset is resolved, and the shape of the answer.
- `http-request-tool`: the agent's general HTTP capability — permitted methods, the declared-policy gate and its fail-closed behavior, header filtering and host-configured credentials, per-content-type response decoding, and size limits.

### Modified Capabilities
- `php-class-decomposition`: its "Extracted collaborators live beside the class they came from" requirement places a collaborator in a namespace named for its **origin class**. The web-fetch collaborator is shared by two tools and has no single origin class, so the requirement needs a clause for that case. Its "Preserved public surfaces" requirement gains a scenario pinning `fetch-web-page`'s MCP contract across the extraction, matching the one that already exists for `SearchWebTool`.

## Impact

- **Code**: two new tools under `src/Services/Mcp/Tools/ChatBot/`; a new shared web-fetch collaborator; `FetchWebPageTool` reduced to a façade; `Mcp/Servers/CodeTalkerServer` tool list; `config/code-talker.php` gains a `tools.http_request` block.
- **Tests**: `ChatBotToolRegistryTest` pins the discovered tool set and must be updated for the two new names. `FetchWebPageToolTest` must pass unchanged — it is the regression guard for the extraction. New tests for both tools.
- **Discovery**: the shared collaborator lives under a tool directory that `DiscoversAiToolHandlers` walks recursively, so it must not extend `Tool` or implement `AiToolHandlerContract` or it registers itself as a phantom tool.
- **Docs**: README's built-in tool list (currently naming three tools) and `CHANGELOG.md`.
- **Version**: additive — a minor bump.
- **Not affected**: the turn event vocabulary, `resources/js/`, and the SSE wire format.
