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

// $response is a plain JSON-RPC 2.0 response array — json_encode() it onto whatever
// transport your host terminates (an HTTP POST body, a stdio pipe, ...).
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

## What lives where

| Layer | Package | Owns |
|-------|---------|------|
| Contracts | `milpa/core` | Framework-wide primitives — events, attributes, DI/container contracts. |
| Tool engine | `milpa/tool-runtime` | `ToolRegistry`, `#[Tool]`, `ToolContext`, the resolve→validate→authorize→execute→audit pipeline. |
| **MCP transport** | **`milpa/mcp-server`** (this package) | `JsonRpcService` (the JSON-RPC 2.0 ⇄ `ToolRegistry` bridge) and the `TokenValidatorInterface`/`Principal` auth seam. |
| Your app | your host / plugins | The concrete HTTP/SSE endpoint, the concrete `TokenValidatorInterface` implementation (token storage, expiry, revocation), and — if you need MCP clients to obtain tokens themselves — an OAuth 2.0 authorization server in front of it. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.3**
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
