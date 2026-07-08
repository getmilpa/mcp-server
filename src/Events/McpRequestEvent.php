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

use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * Dispatched by {@see \Milpa\McpServer\JsonRpcService::handle()} as the readonly half of the
 * `mcp.request` PRE event, right before a resolved JSON-RPC method is dispatched.
 *
 * Always readonly (family convention, core 0.5 KEYSTONE) — this class carries observability
 * data only. The companion mutable {@see \Milpa\Events\InterceptionSlot}, dispatched
 * alongside it under the `'slot'` payload key, is the only thing a listener can use to veto
 * the method (`$slot->stop()`) or serve a canned/cached response in its place
 * (`$slot->shortCircuit($response)`) — never this event object.
 *
 * Fires for every resolved method, including notifications (a request with no `id` member) —
 * `handle()` still owes observability the emit even though the wire response is suppressed.
 */
final class McpRequestEvent
{
    /**
     * @param array<string, mixed> $params the raw, array-normalized `params` member of the
     *                                     incoming JSON-RPC request (never the un-normalized
     *                                     non-array value a malformed request might have sent)
     */
    public function __construct(
        private readonly string $method,
        private readonly array $params,
        private readonly ?ToolContext $ctx,
    ) {
    }

    /**
     * The JSON-RPC method about to be dispatched (e.g. `tools/call`).
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * The request's `params`, already normalized to an array.
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * The authenticated caller context, if the host resolved one, else `null`.
     */
    public function getContext(): ?ToolContext
    {
        return $this->ctx;
    }
}
