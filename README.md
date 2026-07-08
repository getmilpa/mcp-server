<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa McpServer

> The **Model Context Protocol (MCP) transport core** for the Milpa PHP framework, built on **`milpa/core`** and **`milpa/tool-runtime`**. A transport-agnostic JSON-RPC 2.0 dispatcher over the tool-runtime registry (`initialize`, `notifications/initialized`, `tools/list`, `tools/call`), plus the auth seam a host implements to turn a bearer token into an authenticated caller. **No HTTP kernel, no SSE, no concrete token store** — those live in your host application.

[![CI](https://github.com/getmilpa/mcp-server/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/mcp-server/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/mcp-server.svg)](https://packagist.org/packages/milpa/mcp-server)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/mcp-server/)

## Install

```bash
composer require milpa/mcp-server
```

## What it is

Milpa splits its surface into small, dependency-light packages. `milpa/tool-runtime` is the
concrete engine that runs `#[Tool]`-attributed methods through one pipeline (resolve, validate,
authorize, execute, audit); `milpa/mcp-server` is one **transport** for that engine — the
[Model Context Protocol](https://modelcontextprotocol.io/), the JSON-RPC-based protocol LLM
clients speak to discover and call tools over MCP.

This package is deliberately narrow. It ships:

- **`JsonRpcService`** — decodes a JSON-RPC 2.0 request array, dispatches `initialize`,
  `notifications/initialized`, `tools/list`, and `tools/call` against a
  `Milpa\ToolRuntime\ToolRegistry`, and returns a JSON-RPC response array. It never touches
  HTTP, SSE, stdio, or a socket — it operates on plain arrays in, plain arrays out.
- **`Auth\TokenValidatorInterface` + `Auth\Principal`** — the seam a host implements to
  authenticate an MCP caller. The transport layer needs exactly one thing from auth: turn a
  bearer token into a `Principal` (or `null`) it can build a `ToolContext` from. It does not
  need — and this package does not ship — a concrete token store, a Doctrine entity, or an
  identity provider.

What it does **not** ship, on purpose: the HTTP endpoint that terminates MCP's Streamable HTTP
transport, the SSE stream, or an OAuth 2.0 authorization server for token issuance/exchange
(registration, PKCE, the `/oauth/*` endpoints MCP clients probe per RFC 8414/9728). Those are
real, substantial pieces of a production MCP server, but they are **host wiring** — they depend
on your HTTP framework's request/response types, your session/storage layer, and your product's
own auth UI — not on the MCP protocol logic itself. Build them in your host application as an
adapter around `JsonRpcService` and `TokenValidatorInterface`.

## Quick example

```php
use Milpa\McpServer\JsonRpcService;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

$registry = new ToolRegistry(new NullLogger());
// ... register tools on $registry, e.g. via ToolScanner ...

$rpc = new JsonRpcService($registry);

$ctx = new ToolContext(principal: 'user:42', channel: 'mcp', scopes: ['notes:read']);

$response = $rpc->handle([
    'jsonrpc' => '2.0',
    'method' => 'tools/call',
    'params' => ['name' => 'list_notes', 'arguments' => ['page' => 1]],
    'id' => 1,
], $ctx);

// $response is a JSON-RPC 2.0 response array, or null. json_encode() an array response onto
// whatever transport your host terminates (an HTTP POST body, a stdio pipe, ...); a null
// response means the request was a notification and your transport MUST write nothing at all
// — see "Recipes" below.
```

## Events (0.3)

`JsonRpcService` takes an optional second constructor argument: an
`?MilpaEventDispatcherInterface` (from `milpa/core`), nullable and defaulting to `null` (the
same pattern `milpa/tool-runtime`'s `HumanVerifier` uses). Leave it unset and `handle()` behaves
exactly as it did in 0.2 — nothing below is a breaking change for existing callers.

Wired up, `handle()` brackets every *resolved* method dispatch — i.e. once `$method` has passed
the batch/`jsonrpc`/missing-`method` guards, which are protocol-level failures no plugin gets a
say in — with two events:

- **`mcp.request`** (PRE, stoppable) fires right before the method would run, with a payload
  shaped `['event' => McpRequestEvent, 'slot' => InterceptionSlot]`. A listener reads the
  method/params/caller context off the event, and can act on the slot:
  - `$slot->stop()` — pure veto. The method never runs; the caller gets a well-formed
    `-32001 Method vetoed by an mcp.request listener: <method>` JSON-RPC error.
  - `$slot->shortCircuit($response)` — serve a canned or cached response in the method's place.
    The method never runs; `$response` becomes the JSON-RPC `result` as-is.
  - Neither call — the method runs normally, exactly as in 0.2.
- **`mcp.responded`** (POST, readonly) fires afterward with `['event' => McpRespondedEvent]`
  once a response envelope exists, no matter how it was produced (the method ran, a listener
  short-circuited it, or a listener vetoed it). It fires even for notifications (a request with
  no `id` member) — `handle()`'s own return value is still `null` on the wire per the 0.2
  contract, but the event carries the response that *would* have been sent, so observability
  never goes blind just because the wire response was suppressed.

```php
use Milpa\Events\InterceptionSlot;
use Milpa\McpServer\Events\McpRequestEvent;
use Milpa\McpServer\Events\McpRespondedEvent;
use Milpa\McpServer\JsonRpcService;

// A cache plugin: short-circuit tools/call for a known-idempotent tool + args pair.
$dispatcher->subscribe('mcp.request', function (string $eventName, array $payload): void {
    $event = $payload['event']; // McpRequestEvent
    /** @var InterceptionSlot $slot */
    $slot = $payload['slot'];

    if ($event->getMethod() === 'tools/call') {
        $cached = $cache->get($event->getParams());
        if ($cached !== null) {
            $slot->shortCircuit($cached); // the tool never actually runs
        }
    }
});

// An audit plugin: log the outcome of every dispatched method, cache hit or not.
$dispatcher->subscribe('mcp.responded', function (string $eventName, array $payload): void {
    /** @var McpRespondedEvent $event */
    $event = $payload['event'];
    $logger->info('mcp method responded', [
        'method' => $event->getMethod(),
        'ok' => !isset($event->getResponse()['error']),
    ]);
});

$rpc = new JsonRpcService($registry, $dispatcher);
```

## The auth seam

`JsonRpcService` itself takes an already-resolved `?ToolContext` — it has no opinion on how you
got there. The seam this package *does* define is one layer up: how a host turns a raw bearer
token, arriving over whatever transport it terminates, into the `Principal` that becomes that
`ToolContext`.

```php
use Milpa\McpServer\Auth\Principal;
use Milpa\McpServer\Auth\TokenValidatorInterface;

final class DatabaseTokenValidator implements TokenValidatorInterface
{
    public function validate(string $token, ?string $ip = null): ?Principal
    {
        $record = $this->tokens->findValidByToken($token); // your own store, your own rules
        if ($record === null) {
            return null;
        }

        $record->recordUsage($ip); // optional side effect, entirely up to the implementation

        return new Principal(
            id: "user:{$record->ownerId()}",
            displayName: $record->ownerName(),
            scopes: $record->scopes(),
            extra: ['token_prefix' => $record->prefix()],
        );
    }
}
```

Your transport adapter (an HTTP controller, typically) then does three things per request:
extract the bearer token, call `$validator->validate($token, $ip)`, and — on a non-null
`Principal` — build the `ToolContext` via `$principal->toToolContext($ip, $userAgent)` before
handing the request to `JsonRpcService::handle()`. A `null` result means "reject with 401";
nothing about *why* it was null (expired vs. revoked vs. unknown) crosses this seam — that
distinction, if you want it, lives entirely inside your `TokenValidatorInterface`
implementation.

### Why one interface, not two

The obvious alternative is splitting "is this token valid" from "who does it belong to" into
two seams (a validator and a separate principal resolver). This package does not, because
nothing in its own reference host ever calls them independently — every real call site
authenticates a request by immediately needing both the yes/no *and* the principal in the same
breath. A second seam here would be indirection with no consumer, not abstraction.

## Recipes

Three things the first real consumer of this package's stdio transport had to work out by
reading source instead of a doc. Fixed here for the next one.

### Running without auth (a trusted local process)

`JsonRpcService` itself is auth-agnostic — it just takes whatever `?ToolContext` you hand it.
But `milpa/tool-runtime`'s `PolicyGate` ships a built-in `mcp` channel policy that is *not*
agnostic: `channelPolicies['mcp']` is `['allow_all' => false, 'require_auth' => true]`, so **any
`ToolContext` with an empty `principal` gets every `tools/call` silently `FORBIDDEN`** —
including non-mutating reads. Nothing about this surfaces from `JsonRpcService`'s side; the only
way to find it is to read `PolicyGate`'s source or puzzle over a confusing `success: false` on
every single tool call.

If your transport genuinely has no per-request auth — a local stdio server spawned by an agent
you already trust to run the whole process — the fix is a fixed principal and wildcard scopes,
the same "no real auth, but the channel police accepts a hard-coded identity" pattern
`ToolContext::cli()` already uses for the CLI channel:

```php
use Milpa\ToolRuntime\Contracts\ToolContext;

$ctx = ToolContext::mcp(
    requestId: (string) ($request['id'] ?? uniqid('mcp-', true)),
    principal: 'stdio',   // any non-empty string satisfies require_auth
    scopes: ['*'],
);
```

**Security caveat:** this is process-level trust, not per-caller authentication — every request
through this process runs as `stdio` with every scope. It is only appropriate when you already
trust whoever can talk to the process (e.g. an agent you spawned yourself over a private pipe),
never when the transport is reachable by anyone else (HTTP, a shared socket, a multi-tenant
host, ...). For real per-caller auth, implement `Auth\TokenValidatorInterface` and resolve a
distinct `Principal` per request instead — see "The auth seam" above.

### Notifications: branch on `null`, not on your own `id` check

A JSON-RPC notification is a request with no `id` member at all (§4.1) — the server MUST NOT
reply, not even with an empty body. As of 0.2, `handle()` knows this itself: it still dispatches
the method (so any side effects happen), but returns `null` instead of a response array whenever
the original request had no `id` key. **Your transport's whole job is "if `handle()` returned
null, write nothing"** — there is no need to re-derive notification-ness from the raw request
yourself anymore.

```php
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode(trim($line), true);
    // ... decode/validate $request as JSON, handle parse errors, etc ...

    $response = $rpc->handle($request, $ctx);

    if ($response === null) {
        continue; // notification (or a batch/notification hybrid edge case) — write nothing
    }

    fwrite(STDOUT, json_encode($response) . "\n");
    fflush(STDOUT);
}
```

One wrinkle worth knowing about, not one you need to handle yourself: an explicit
`{"id": null, ...}` member IS a request, not a notification — `handle()` tells the two apart
internally with `array_key_exists('id', $request)`, deliberately never `isset()` (which would
treat a present-but-null `id` the same as a missing one and wrongly suppress a reply the caller
expected). You don't need to replicate that distinction; just branch on `handle()`'s return
value.

### Batches: rejected, on purpose

`handle()` is single-request only and always has been — one decoded request array in, one
response array (or `null`) out. As of 0.2 it also says so on the wire: if the decoded request is
itself a list (a JSON-RPC batch — `[{...}, {...}]`, or even the empty `[]`) it returns a
`-32600 Batch requests are not supported` error with `id: null`, instead of silently misreading
the list as one malformed request and leaving a batching-capable client hanging for responses
that will never arrive.

This is also why `initialize()` now declares protocol revision **`2025-06-18`** rather than the
earlier `2025-03-26`: that revision dropped batching from MCP's JSON-RPC transport entirely, so
a server that only ever spoke single-request JSON-RPC is now spec-exact instead of an
approximation with an undocumented gap. (The `2025-06-18` revision's other, capability-gated
optional features — `structuredContent`, elicitation — are out of scope for this package; it
declares the revision, not every feature introduced in it.)

If your host still wants its own batch guard ahead of `handle()` — e.g. to short-circuit before
touching your own auth or logging — that's harmless. `handle()`'s guard makes it redundant, not
wrong; keep it as defense-in-depth if you'd rather not depend on this package's internals for
that decision.

## What lives where

| Layer | Package | Owns |
|-------|---------|------|
| Contracts | `milpa/core` | Framework-wide primitives — events, attributes, DI/container contracts. |
| Tool engine | `milpa/tool-runtime` | `ToolRegistry`, `#[Tool]`, `ToolContext`, the resolve→validate→authorize→execute→audit pipeline. |
| **MCP transport** | **`milpa/mcp-server`** (this package) | `JsonRpcService` (the JSON-RPC 2.0 ⇄ `ToolRegistry` bridge) and the `TokenValidatorInterface`/`Principal` auth seam. |
| Your app | your host / plugins | The concrete HTTP/SSE endpoint, the concrete `TokenValidatorInterface` implementation (token storage, expiry, revocation), and — if you need MCP clients to obtain tokens themselves — an OAuth 2.0 authorization server in front of it. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.5** (0.3's events + 0.5's
  `InterceptionSlot` — see "Events (0.3)" above)
- [`milpa/tool-runtime`](https://packagist.org/packages/milpa/tool-runtime) **^0.2**
- [`psr/log`](https://packagist.org/packages/psr/log) **^3**

## Documentation

**Full API reference: [getmilpa.github.io/mcp-server](https://getmilpa.github.io/mcp-server/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=mcp-server)**.
