<?php

/**
 * This file is part of Milpa McpServer — the Model Context Protocol (MCP) transport core
 * of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/mcp-server
 */

declare(strict_types=1);

namespace Milpa\McpServer;

use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\McpServer\Events\McpRequestEvent;
use Milpa\McpServer\Events\McpRespondedEvent;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;

/**
 * JSON-RPC 2.0 dispatcher for the Model Context Protocol, running over a {@see ToolRegistry}.
 *
 * Transport-agnostic by design: it takes a decoded JSON-RPC request array and an optional
 * caller {@see ToolContext}, and returns a JSON-RPC response array (or `null` — see
 * {@see self::handle()}) — nothing here knows about HTTP, SSE, stdio, or how the caller
 * authenticated. A host adapter owns decoding the wire request, resolving the caller into a
 * `ToolContext` (see `Auth\TokenValidatorInterface`), and encoding the response back onto its
 * transport of choice. Covers the MCP method set this package implements: `initialize`,
 * `notifications/initialized`, `tools/list`, `tools/call`. Declares protocol revision
 * `2025-06-18` (see {@see self::initialize()}), which dropped batching from the spec — this
 * class only ever spoke single-request JSON-RPC, so that revision is now spec-exact instead of
 * an approximation.
 *
 * **Events (0.3, additive, ADDITIVE around the 0.2 semantics above — never changes them).** An
 * optional {@see MilpaEventDispatcherInterface} — nullable, HumanVerifier pattern, a service
 * with no dispatcher wired keeps working byte-for-byte as before — brackets every resolved
 * method dispatch (batch rejection / bad `jsonrpc` / missing `method` never reach this: those
 * are protocol-level failures, not a "method" a plugin could reasonably want to observe or
 * veto):
 * - `mcp.request` (PRE, stoppable) fires with `['event' => McpRequestEvent, 'slot' =>
 *   InterceptionSlot]` right before the method would run. A listener may call `$slot->stop()`
 *   to veto the method outright (the method never runs; the caller gets a `-32001` JSON-RPC
 *   error) or `$slot->shortCircuit($response)` to serve a canned/cached response in its place
 *   (the method never runs; `$response` becomes the JSON-RPC `result` as-is).
 * - `mcp.responded` (POST, readonly) fires with `['event' => McpRespondedEvent]` once a
 *   response envelope exists — whether the method actually ran, a listener short-circuited it,
 *   or a listener vetoed it. Fires even for notifications (no `id` member), where `handle()`'s
 *   own return value is `null` on the wire per the 0.2 contract — the event still carries the
 *   response that *would* have been sent, for observability.
 */
class JsonRpcService
{
    private ToolRegistry $toolRegistry;

    private ?MilpaEventDispatcherInterface $dispatcher;

    public function __construct(ToolRegistry $toolRegistry, ?MilpaEventDispatcherInterface $dispatcher = null)
    {
        $this->toolRegistry = $toolRegistry;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Dispatch one JSON-RPC 2.0 request through the MCP method set and return its response.
     *
     * **Never throws.** Every protocol-level problem — an invalid/missing `jsonrpc` member, a
     * missing or non-string `method`, an unsupported batch, or an exception raised while
     * dispatching — comes back as a well-formed JSON-RPC `error` array with the incoming `id`
     * preserved (or `null` when there was none to preserve), never a PHP exception. Tool-level
     * exceptions never reach this method at all: {@see ToolRegistry::call()} already catches
     * them internally and surfaces a `ToolResult` with `success: false` instead of throwing.
     *
     * **Returns `null` for notifications — read this before wiring a transport.** A JSON-RPC
     * notification is a request with no `id` member at all (JSON-RPC 2.0 §4.1): the server
     * MUST NOT reply, not even with an empty body. This method is the one place that sees the
     * *original* request shape, so it owns that decision instead of pushing it onto every
     * transport: when `$request` has no `id` key, `handle()` still dispatches the method (for
     * whatever side effects it has) but returns `null` instead of a response array. **Callers
     * MUST write nothing to the wire when `handle()` returns `null`** — no bytes, no empty
     * line, nothing.
     *
     * The notification test is `array_key_exists('id', $request)`, deliberately never
     * `isset()`: an explicit `{"id": null, ...}` member IS a request per the spec and always
     * gets answered (with `"id": null` in the response) — `isset()` would misclassify it as a
     * notification because it also returns false for a `null` value, silently swallowing a
     * request the caller expected a reply to. Do not "simplify" this to `isset()`.
     *
     * A JSON-RPC batch — the decoded request is itself a list, e.g. `[{...}, {...}]` or the
     * empty `[]` — is rejected outright with a `-32600` error and `id: null`. This class is
     * single-request only; there is no batching fallback, and batching itself was removed from
     * the protocol as of the `2025-06-18` revision this server declares (see the class
     * docblock).
     *
     * **Events (0.3).** Once `$method` is resolved to a non-empty string — i.e. past the
     * batch/`jsonrpc`/missing-`method` guards above, which are protocol-level failures no
     * plugin gets a say in — this method brackets the dispatch with `mcp.request` (PRE,
     * stoppable) and `mcp.responded` (POST, readonly). See the class docblock for the full
     * contract; in short, a listener may veto or short-circuit the method via the
     * {@see \Milpa\Events\InterceptionSlot} handed to `mcp.request`, and `mcp.responded` always
     * fires afterward with whatever response resulted — including for notifications, where the
     * *return value* of this method is still `null` per the paragraph above.
     *
     * @param array<int|string, mixed> $request Decoded JSON-RPC request. May also be a plain
     *                                          list (int-indexed) when the caller sent a
     *                                          batch — that shape is detected and rejected
     *                                          before anything else.
     *
     * @return array<string, mixed>|null The JSON-RPC response, or `null` to signal "this was a
     *                                   notification — write nothing".
     */
    public function handle(array $request, ?ToolContext $ctx = null): ?array
    {
        // A JSON-RPC batch decodes as a PHP list (empty or not) — this server is single-request
        // only (see class docblock), so batches are refused loudly here instead of silently
        // hanging a batching-capable client that would otherwise wait forever for N responses
        // that will never arrive.
        if (array_is_list($request)) {
            return [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Batch requests are not supported',
                ],
                'id' => null,
            ];
        }

        // A notification has no "id" member at all — not just a null one. array_key_exists()
        // (not isset()) is required: isset() would misclassify an explicit {"id": null, ...}
        // request as a notification. See the method docblock.
        $isNotification = !array_key_exists('id', $request);
        $id = $request['id'] ?? null;

        // Basic JSON-RPC 2.0 validation
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return $isNotification ? null : [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Invalid Request',
                ],
                'id' => $id,
            ];
        }

        $method = $request['method'] ?? null;
        $params = $request['params'] ?? [];

        if (!$method || !is_string($method)) {
            return $isNotification ? null : [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32601,
                    'message' => 'Method not found',
                ],
                'id' => $id,
            ];
        }

        $normalizedParams = is_array($params) ? $params : [];

        // --- mcp.request (PRE, stoppable) ---------------------------------------------------
        // See class docblock. A listener reading $slot back after dispatch() returns may have
        // vetoed the method (isStopped(), no result) or short-circuited it (hasResult()) — both
        // mean the method below never runs. No dispatcher wired -> $slot stays fresh/unstopped
        // and this is a no-op, byte-identical to pre-0.3 behavior.
        $slot = new InterceptionSlot();
        $this->dispatcher?->dispatch(
            'mcp.request',
            ['event' => new McpRequestEvent($method, $normalizedParams, $ctx), 'slot' => $slot]
        );

        if ($slot->hasResult()) {
            $response = [
                'jsonrpc' => '2.0',
                'result' => $slot->getResult(),
                'id' => $id,
            ];
        } elseif ($slot->isStopped()) {
            $response = [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32001,
                    'message' => "Method vetoed by an mcp.request listener: {$method}",
                ],
                'id' => $id,
            ];
        } else {
            try {
                $result = $this->dispatch($method, $normalizedParams, $ctx);
                $response = [
                    'jsonrpc' => '2.0',
                    'result' => $result,
                    'id' => $id,
                ];
            } catch (\Exception $e) {
                $response = [
                    'jsonrpc' => '2.0',
                    'error' => [
                        'code' => $e->getCode() ?: -32603,
                        'message' => $e->getMessage(),
                    ],
                    'id' => $id,
                ];
            }
        }

        // --- mcp.responded (POST, readonly) -------------------------------------------------
        // Fires unconditionally, even for notifications (see below) — observability must see
        // the response that was computed, independent of whether it ever reaches the wire.
        $this->dispatcher?->dispatch('mcp.responded', ['event' => new McpRespondedEvent($method, $response)]);

        if ($isNotification) {
            return null;
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function dispatch(string $method, array $params, ?ToolContext $ctx = null): mixed
    {
        switch ($method) {
            case 'initialize':
                return $this->initialize($params);
            case 'notifications/initialized':
                // Acknowledgment-only notification — no side effect today. Dispatched
                // uniformly like every other method; the null it returns only matters to the
                // caller when handle() has independently determined this was actually a
                // notification (no "id" member) — see handle().
                return null;
            case 'tools/list':
                return $this->listTools();
            case 'tools/call':
                return $this->callTool($params, $ctx);
            default:
                throw new \Exception("Method not found: $method", -32601);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        return [
            'protocolVersion' => '2025-06-18',
            'serverInfo' => [
                'name' => 'Milpa MCP Server',
                'version' => '1.0.0',
            ],
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listTools(): array
    {
        return [
            'tools' => $this->toolRegistry->getToolSummaries(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(array $params, ?ToolContext $ctx = null): array
    {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];

        if (!is_string($name) || $name === '') {
            throw new \Exception('Invalid params: "name" is required', -32602);
        }

        $result = $this->toolRegistry->call($name, is_array($args) ? $args : [], $ctx);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result),
                ],
            ],
        ];
    }
}
