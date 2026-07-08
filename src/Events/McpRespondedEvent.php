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

namespace Milpa\McpServer\Events;

/**
 * Dispatched by {@see \Milpa\McpServer\JsonRpcService::handle()} as the `mcp.responded` POST
 * event, once a JSON-RPC response array has been computed for a resolved method — whatever
 * produced it: the method actually ran, a listener short-circuited `mcp.request` with a canned
 * response, or a listener vetoed the method and this carries the resulting error response.
 *
 * Always readonly (family convention, core 0.5 KEYSTONE) — pure post-hoc notification, no
 * slot, nothing a listener can mutate. Fires unconditionally, including for notifications
 * (a request with no `id` member): `handle()` still returns `null` on the wire for those per
 * the 0.2 null-signal contract, but this event still carries the response that WOULD have been
 * sent, for observability.
 */
final class McpRespondedEvent
{
    /**
     * @param array<string, mixed> $response the full JSON-RPC response envelope
     *                                       (`jsonrpc`/`result-or-error`/`id`) — the exact
     *                                       array `handle()` would return for a non-notification
     */
    public function __construct(
        private readonly string $method,
        private readonly array $response,
    ) {
    }

    /**
     * The JSON-RPC method that was dispatched (e.g. `tools/call`).
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * The full JSON-RPC response envelope produced for this request.
     *
     * @return array<string, mixed>
     */
    public function getResponse(): array
    {
        return $this->response;
    }
}
