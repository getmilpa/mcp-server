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

namespace Milpa\McpServer\Auth;

/**
 * The auth seam the MCP HTTP/SSE transport needs from its host: turn a bearer token into an
 * authenticated {@see Principal}, or null if it doesn't authenticate.
 *
 * One method, not a split "is this token valid" / "who does it belong to" pair — the transport
 * never validates a token without immediately needing the principal it resolves to, and never
 * resolves a principal from anything but a token, so a second seam would only add indirection.
 * Implement this once per host token/identity store (a database-backed API token table, a JWT
 * verifier, a static token list for local dev, …) and hand the instance to your transport
 * adapter (e.g. the controller that terminates the MCP HTTP endpoint).
 */
interface TokenValidatorInterface
{
    /**
     * Validate a raw bearer token and resolve the {@see Principal} behind it.
     *
     * Implementations are expected to also apply their own liveness rules (expiry, revocation,
     * owner still active, …) and MAY record usage (e.g. last-used-at/ip) as a side effect of a
     * successful validation. Return null for anything that is not currently a valid token:
     * unknown, expired, revoked, or belonging to an inactive principal.
     */
    public function validate(string $token, ?string $ip = null): ?Principal;
}
