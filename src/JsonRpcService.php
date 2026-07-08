<?php

/**
 * This file is part of Milpa McpServer — the Model Context Protocol (MCP) transport core
 * of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/mcp-server
 */

declare(strict_types=1);

namespace Milpa\McpServer;

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
 */
class JsonRpcService
{
    private ToolRegistry $toolRegistry;

    public function __construct(ToolRegistry $toolRegistry)
    {
        $this->toolRegistry = $toolRegistry;
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

        try {
            $result = $this->dispatch($method, is_array($params) ? $params : [], $ctx);
        } catch (\Exception $e) {
            return $isNotification ? null : [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => $e->getCode() ?: -32603,
                    'message' => $e->getMessage(),
                ],
                'id' => $id,
            ];
        }

        if ($isNotification) {
            return null;
        }

        return [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ];
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
