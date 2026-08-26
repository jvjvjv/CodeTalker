## 1. Extract the gate into shared code

- [x] 1.1 Add `src/Services/Web/RequestPolicy.php` — a `final class` with promoted `public readonly` `allowedMethods`, `allowPrivateHosts`, `allowedHosts`, and factories `declared(array $input, array $supportedMethods)` and `publicHostsOnly()`. An empty `allowedMethods` means unrestricted.
- [x] 1.2 Add `src/Services/Web/HostGate.php` with `refuse(string $url, string $method, RequestPolicy $policy): ?string`, moving `refuseUnsupportedScheme()`, the host and method checks, `isPrivateHost()`, and `addressesFor()` off `HttpRequestTool`. Keep `addressesFor()` `protected` as the test seam and keep the "unresolvable counts as private" rule.
- [x] 1.3 Make `HostGate::refuse()` report host and scheme failures without naming `allowed_methods`, so `fetch-web-page` can surface a refusal that matches its own schema.
- [x] 1.4 Inject `HostGate` into `WebFetcher` via the constructor so tests can supply one.

## 2. Route both tools through the shared gate

- [x] 2.1 Rewrite `HttpRequestTool` to build a `RequestPolicy` and delegate to `HostGate`, keeping its fail-closed refusal for a missing or empty `allowed_methods` **before** the policy is constructed. Its existing refusal messages must not drift.
- [x] 2.2 Confirm `tests/Feature/HttpRequestToolTest.php` passes with no assertion changes — it is the regression guard for this relocation.
- [x] 2.3 Change `WebFetcher::fetchPage()` to take a `RequestPolicy` and run through `sendFollowingRedirects()` with a `validateHop` built from it, so one redirect implementation serves both tools.
- [x] 2.4 Delete the now-unused unchecked redirect path in `send()`, or keep `followRedirects` only if something still needs it.

## 3. The fetch tool's policy input

- [x] 3.1 Add an optional `request_policy` object to `FetchWebPageTool::schema()` with `allow_private_hosts` and `allowed_hosts` only. Its `#[Description]` should say that omitting it means public hosts only.
- [x] 3.2 Build `RequestPolicy::publicHostsOnly()` when the input is absent, and `RequestPolicy::declared()` when present.
- [x] 3.3 Supply the remediation sentence naming `request_policy.allow_private_hosts` on a host refusal, and assert in a test that it never mentions `allowed_methods`.
- [x] 3.4 Leave `#[Name]`, the handler signature, the response keys, and the four existing inputs untouched.

## 3b. Pin the validated address into the connection

- [x] 3b.1 Have `HostGate` return the resolved address alongside its verdict, rather than discarding it after the private-range check.
- [x] 3b.2 Pass it to cURL from `WebFetcher` as `withOptions(['curl' => [CURLOPT_RESOLVE => ["{host}:{port}:{address}"]]])`, deriving the port from the URL with the scheme default (80/443) filled in.
- [x] 3b.3 Pin per hop inside `sendFollowingRedirects()`, using each hop's own validated address rather than the first hop's.
- [x] 3b.4 Handle IPv6: bracketed in the URL host, unbracketed in the resolve entry.
- [x] 3b.5 Add a test asserting the resolve option is present on the outgoing request, so a handler change that drops it fails the suite instead of silently regressing.

## 4. Tests

- [x] 4.1 Change `FetchWebPageToolTest`'s connection-failure case from `https://example.invalid/page` to a resolvable host, keeping the "Could not connect to ..." assertion intact so the case is still covered. This is the one file the previous change pinned; the edit is deliberate and scoped to that URL.
- [x] 4.2 Add a fixed host-to-address `HostGate` subclass to `FetchWebPageToolTest` so it does not touch DNS, matching the seam `HttpRequestToolTest` already uses.
- [x] 4.3 Add fetch-tool tests for: no policy fetches a public page; no policy refuses loopback, RFC1918, and link-local; `allow_private_hosts: true` permits loopback; `allowed_hosts` refuses an outside host; refusal text names `allow_private_hosts` and not `allowed_methods`.
- [x] 4.4 Add fetch-tool redirect tests: public page redirecting into a private network is refused and never requested; a permitted redirect is followed and reports the final `url`.
- [x] 4.5 Verify the new refusal tests fail when the gate is bypassed, so they discriminate rather than passing on `Http::fake()` semantics.
- [x] 4.6 Run `composer test` and confirm the whole suite passes.

## 5. Documentation and release

- [x] 5.1 Update the README's `fetch-web-page` entry and the tool section: the `request_policy` input, the public-hosts-only default, redirect re-validation, and why this tool's declaration is optional while `http-request`'s is required.
- [x] 5.2 Delete the `fetch-web-page` Known Issue from the 0.11.0 CHANGELOG entry and add the behavior change under Breaking Changes and the capability under New Features.
- [x] 5.3 Keep security analysis out of `CHANGELOG.md` and the README. Record the behavior a host must react to under Breaking Changes; the threat model belongs in the gitignored `CLAUDE.md` Security Notes section.
- [x] 5.4 Update `CLAUDE.md`'s Security Notes: mark DNS rebinding closed for both tools by pinning, and clear the "Known unfixed" entry once this lands.
