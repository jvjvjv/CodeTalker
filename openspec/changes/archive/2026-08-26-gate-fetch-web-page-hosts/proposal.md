## Why

`fetch-web-page` performs no host checks at all. It will fetch `http://127.0.0.1/`, `http://10.0.0.5/admin`, or `http://169.254.169.254/latest/meta-data/` on the model's say-so, and it follows Guzzle's default five redirects with no validation of any hop — so a permitted public URL can bounce it onto an internal address too.

This shipped as a Known Issue in the 0.11.0 draft. It should not ship at all: 0.11.0 is untagged and unpushed, and the same release hardens `http-request` against exactly this. Shipping one tool with a redirect-validated host gate next to a second tool with neither is worse than shipping neither — it reads as though `fetch-web-page` was considered and found safe.

## What Changes

- **`fetch-web-page` gains an optional `request_policy` input**, the same idiom `http-request` uses, minus `allowed_methods` — the tool is GET-only, so declaring methods is noise:

  ```jsonc
  "request_policy": { "allow_private_hosts": false, "allowed_hosts": ["wiki.internal"] }
  ```

  The model keeps the decision. Reaching an internal host remains an explicit declaration, recorded in the transcript and the `AiLlmMessage` log.

- **Absent a policy, only public hosts are reachable.** The declaration is optional; the permission never is. A fetch with no `request_policy` behaves exactly as one declaring `allow_private_hosts: false`.

- **`fetch-web-page` stops following redirects blindly.** Automatic redirects are disabled and each hop is re-validated through the same gate, capped at 5 hops, matching `http-request`.

- **The host gate and the redirect loop move into `Services/Web/`**, shared by both tools rather than living in `HttpRequestTool`. One implementation, one place to fix.

- **The validated address is pinned into the connection.** Both tools resolve a host once, at the gate, and pass that address to cURL via `CURLOPT_RESOLVE` so the connection cannot resolve a second time to somewhere else. This closes DNS rebinding, which was previously recorded as unfixable in-process.

- **BREAKING**: a bot fetching a private-network page starts being refused until the model declares `allow_private_hosts`. `fetch-web-page` gains a fifth input.

### Why optional here and required on `http-request`

The two tools have different dangerous surfaces. `http-request`'s is *methods*: a missing policy means "I don't know whether you intend to mutate anything," which has no safe guess, so refusing is the only correct answer. `fetch-web-page`'s is *only the host*, and absence there has an unambiguous safe answer — public only. Failing closed would spend a wasted round trip on every ordinary page fetch to obtain a field whose default the caller already wanted.

## Capabilities

### New Capabilities

None. This extends an existing tool and relocates shared code.

### Modified Capabilities

- `http-request-tool`: its redirect and private-host requirements are rewritten to describe a shared gate serving both tools rather than behavior private to `http-request`.
- `php-class-decomposition`: the `add-temporal-and-http-request-tools` change pinned `fetch-web-page`'s inputs as exactly `url`, `keep_html`, `truncate_content`, and `target_selector`. That scenario is amended for the fifth input. The rest of the tool's contract — its `#[Name]`, its handler signature, its response keys, and its existing error strings — stays pinned.

## Impact

- **Code**: `WebFetcher` (gate and redirect loop move in), `FetchWebPageTool` (new input), `HttpRequestTool` (delegates instead of owning the checks).
- **Tests**: `FetchWebPageToolTest`'s connection-failure case fetches `https://example.invalid/page`, which is unresolvable and therefore refused by the gate's "unresolvable counts as private" rule. That assertion changes — the one file the previous change deliberately pinned unmodified. Doing it in the open here is the point.
- **Release**: folds into the unshipped 0.11.0. Its `fetch-web-page` Known Issue is deleted rather than carried.
- **Resolved**: DNS rebinding, via address pinning. Network egress control is still the stronger control — it covers `laravel/ai`'s own calls and any host-registered tool — but the package no longer depends on it for these two tools.
