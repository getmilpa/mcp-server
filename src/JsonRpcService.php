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
 * caller {@see ToolContext}, and returns a JSON-RPC response array — nothing here knows about
 * HTTP, SSE, stdio, or how the caller authenticated. A host adapter owns decoding the wire
 * request, resolving the caller into a `ToolContext` (see `Auth\TokenValidatorInterface`), and
 * encoding the response back onto its transport of choice. Covers the MCP method set this
 * package implements: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`.
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
     * Never throws for a well-formed-but-unknown method or a failed tool call — those come
     * back as a JSON-RPC `error` member. Only a malformed envelope (missing/wrong `jsonrpc`,
     * missing `method`) throws, since there is no `id` to safely key a response on.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    public function handle(array $request, ?ToolContext $ctx = null): array
    {
        // Basic JSON-RPC 2.0 validation
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            throw new \Exception('Invalid Request', -32600);
        }

        $method = $request['method'] ?? null;
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        if (!$method || !is_string($method)) {
            throw new \Exception('Method not found', -32601);
        }

        try {
            $result = $this->dispatch($method, is_array($params) ? $params : [], $ctx);

            return [
                'jsonrpc' => '2.0',
                'result' => $result,
                'id' => $id,
            ];
        } catch (\Exception $e) {
            return [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => $e->getCode() ?: -32603,
                    'message' => $e->getMessage(),
                ],
                'id' => $id,
            ];
        }
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
                return new \stdClass(); // Acknowledge notification
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
            'protocolVersion' => '2025-03-26',
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
