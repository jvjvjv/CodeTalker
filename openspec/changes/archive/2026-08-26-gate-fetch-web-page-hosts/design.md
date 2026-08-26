## Context

0.11.0 added `HttpRequestTool` with a declared-policy gate and per-hop redirect validation. Both live as private methods on that tool: `refuseUnsupportedScheme()`, `refuseOutsidePolicy()`, `isPrivateHost()`, `addressesFor()`, and the `validateHop` closure it hands to `WebFetcher::request()`. `WebFetcher::sendFollowingRedirects()` owns the hop loop but delegates the decision back to the tool.

`fetch-web-page` goes through `WebFetcher::fetchPage()`, which validates only the URL's *shape* and then sends with Guzzle's redirects on. It has neither check.

The release is untagged and unpushed, so this is a correction to 0.11.0 rather than a follow-up to it.

## Goals / Non-Goals

**Goals:**

- Give `fetch-web-page` the same host gate and redirect validation `http-request` has.
- Keep the decision with the model, expressed in the same vocabulary across both tools.
- Make absence of a declaration safe rather than fatal.
- Leave one implementation of the gate, not two.
- Connect to the address the gate actually validated.

**Non-Goals:**

- Replacing network egress control. Pinning closes rebinding for these two tools; egress control still covers everything else the process can reach.
- A host-side ceiling over what a declared policy may grant. Still deferred, now for both tools rather than one.
- Any change to `fetch-web-page`'s name, handler signature, response keys, or existing error strings.

## Decisions

### 1. The gate becomes a value object plus a checker in `Services/Web/`

`Services/Web/RequestPolicy` — a `final class` with promoted `public readonly` properties (`allowedMethods`, `allowPrivateHosts`, `allowedHosts`) and named static factories:

- `RequestPolicy::declared(array $input, array $supportedMethods)` — parses a model-supplied `request_policy`.
- `RequestPolicy::publicHostsOnly()` — the `fetch-web-page` default when nothing is declared: no method restriction, private hosts denied, no host allow-list.

`Services/Web/HostGate` owns `refuse(string $url, string $method, RequestPolicy $policy): ?string` and the address resolution behind it, returning a caller-facing message or null.

*Alternative considered:* leave the checks on `HttpRequestTool` and have `FetchWebPageTool` extend or call into it. Rejected — a tool is not a library, and inheriting one tool from another to share a guard couples their MCP contracts together.

*Alternative considered:* fold the gate into `WebFetcher` as more private methods. Rejected — `WebFetcher` is already the largest class in the change and its job is fetching, not authorization. Splitting the decision out keeps the hop loop readable and the gate independently testable.

### 2. `allowedMethods` is optional on the policy, not absent from it

`RequestPolicy` keeps `allowedMethods`, but an empty list means "unrestricted" rather than "refuse everything". `fetch-web-page`'s schema omits the field entirely and always builds a policy with it empty; `HttpRequestTool` keeps requiring it and refuses an empty declaration *before* constructing the policy.

This puts the fail-closed rule where it belongs — in the tool that needs it — instead of encoding one tool's strictness into a type both share. `http-request`'s existing refusal message and behavior are unchanged.

### 3. Address resolution stays a protected seam, moved with the gate

`HostGate::addressesFor()` stays `protected` so tests substitute a fixed host-to-address map instead of touching DNS. Both tools' tests use the same subclass trick, and `WebFetcher` takes the gate by constructor injection so a test can supply one.

The "a name that does not resolve counts as private" rule moves across unchanged. It is what makes `https://example.invalid/page` — the URL `FetchWebPageToolTest` uses for its connection-failure case — now refuse at the gate.

### 4. `fetchPage()` gets the same hop loop `request()` has

`sendFollowingRedirects()` becomes the path for both. `fetchPage()` passes a `validateHop` derived from its own policy, exactly as `request()` does, so there is one redirect implementation rather than a checked path and an unchecked one.

The 301/302/303-becomes-GET rule is irrelevant for `fetch-web-page` (already GET) but costs nothing to share.

### 5. The validated address is pinned into the connection

`HostGate::refuse()` already resolves the host to decide whether it is private. That resolved address is returned alongside the verdict and passed to cURL:

```php
->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]]])
```

Guzzle's `CurlFactory` passes `$options['curl']` through as raw cURL options, and Laravel's `withOptions()` forwards them, so this needs no new dependency. Because the hop loop validates each redirect destination itself, every hop pins its own address rather than inheriting the first one's.

This is what closes DNS rebinding: the gate's decision and the socket's destination become the same fact instead of two independent lookups.

Three caveats, all accepted:

- **cURL-handler specific.** Under a non-cURL Guzzle handler the option is ignored and behavior falls back to today's — a resolve at connect time. The package has no non-cURL deployment story, and the gate still runs, so this degrades to the pre-pinning posture rather than to nothing.
- **Port must be explicit.** `CURLOPT_RESOLVE` entries are `host:port:address`, so the port has to be derived from the URL with the scheme default filled in (80/443). Getting it wrong silently fails to pin rather than erroring.
- **IPv6 needs bracketing** in the URL host but not in the resolve entry.

*Alternative considered:* re-check the address after connecting, via `CURLINFO_PRIMARY_IP`. Rejected — by then the request has already been sent, so a rebind has already reached the internal service. Reading the answer after the fact is not a gate.

### 6. Refusal messages name the tool's own vocabulary

`fetch-web-page`'s refusal tells the model to declare `request_policy.allow_private_hosts` — it must not mention `allowed_methods`, which its schema does not have. `HostGate::refuse()` therefore reports the host failure generically and each tool supplies the remediation sentence.

## Risks / Trade-offs

- **A pinned test changes** → `FetchWebPageToolTest`'s connection-failure assertion moves from "could not connect" to a gate refusal, because `example.invalid` does not resolve. The previous change pinned that file unmodified as its extraction guard, and that guard did its job. Mitigation: change the test's URL to a resolvable host and keep the connection-failure assertion intact, so the case is still covered rather than traded away. Every other assertion in the file stays untouched.

- **A host legitimately fetching internal pages breaks** → A bot reading an internal wiki starts getting refused. Mitigation: the refusal names the exact field to declare, so a capable model recovers within the same turn. Documented under Breaking Changes, not buried.

- **Two tools, one gate, different strictness** → A reader may assume `fetch-web-page` fails closed because `http-request` does. Mitigation: the asymmetry and its rationale are stated in the README next to both tools, not only in this design.

- **The gate is still model-declared** → Unchanged from 0.11.0 and deliberate: a prompt-injected model declares a permissive policy. The gate stops accidents and creates an audit trail; it is not a boundary, and pinning does not make it one. Egress control remains the boundary.

- **Pinning silently no-ops under a non-cURL handler** → Mitigation: a test asserts the resolve option is present on the outgoing request, so a handler change that drops it fails the suite rather than quietly regressing.

## Migration Plan

Folds into the unshipped 0.11.0. Its `fetch-web-page` Known Issue is removed and the behavior change is recorded under Breaking Changes. No migration; `allowed_tools` values are untouched.

## Open Questions

- **The host-side ceiling**, still deferred, now covering both tools: whether `code-talker.tools.*` should cap what any declared policy may grant. It converts the gate from "model declares, tool obeys" into "model declares, host adjudicates" — a different capability, and the one that would make this a boundary rather than a guardrail.
