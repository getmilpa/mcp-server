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

namespace Milpa\McpServer\Auth;

use Milpa\ToolRuntime\Contracts\ToolContext;

/**
 * The authenticated caller behind an MCP request, resolved from a bearer token by a
 * host-supplied {@see TokenValidatorInterface}.
 *
 * Deliberately narrow: it carries only what the JSON-RPC/SSE transport layer actually needs
 * to build a {@see ToolContext} and to log who called — not a copy of the host's user/token
 * entity (no Doctrine, no ORM relations). A host with a database-backed token/member model
 * maps its own types down to this DTO inside its `TokenValidatorInterface` implementation.
 */
final readonly class Principal
{
    /**
     * @param string               $id          Stable identifier for {@see ToolContext::$principal}
     *                                          (e.g. `"user:42"`). Never the raw token.
     * @param string               $displayName Human-readable label for logging/audit trails
     *                                          (e.g. a member's name). Never a secret.
     * @param list<string>         $scopes      Scopes granted to this caller, passed through
     *                                          verbatim to {@see ToolContext::$scopes}.
     * @param array<string, mixed> $extra       Host-defined extra context carried onto
     *                                          {@see ToolContext::$extra} verbatim (e.g. a
     *                                          numeric member id, a token prefix for logs).
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public array $scopes = [],
        public array $extra = [],
    ) {
    }

    /**
     * Build the {@see ToolContext} an authenticated MCP request should execute tools under.
     */
    public function toToolContext(?string $ip = null, ?string $userAgent = null, string $channel = 'mcp'): ToolContext
    {
        return new ToolContext(
            principal: $this->id,
            channel: $channel,
            scopes: $this->scopes,
            ip: $ip,
            userAgent: $userAgent,
            extra: $this->extra,
        );
    }
}
