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

namespace Milpa\McpServer\Tests\Auth;

use Milpa\McpServer\Auth\Principal;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

class PrincipalTest extends TestCase
{
    public function testConstructorStoresAllFields(): void
    {
        $principal = new Principal(
            id: 'user:42',
            displayName: 'Ada Lovelace',
            scopes: ['tools:read', 'tools:write'],
            extra: ['team_member_id' => 42, 'token_prefix' => 'mcp_abcd1234'],
        );

        $this->assertSame('user:42', $principal->id);
        $this->assertSame('Ada Lovelace', $principal->displayName);
        $this->assertSame(['tools:read', 'tools:write'], $principal->scopes);
        $this->assertSame(['team_member_id' => 42, 'token_prefix' => 'mcp_abcd1234'], $principal->extra);
    }

    public function testConstructorDefaultsScopesAndExtraToEmpty(): void
    {
        $principal = new Principal(id: 'user:1', displayName: 'Anon');

        $this->assertSame([], $principal->scopes);
        $this->assertSame([], $principal->extra);
    }

    public function testToToolContextMapsFieldsAndDefaultsChannelToMcp(): void
    {
        $principal = new Principal(
            id: 'user:42',
            displayName: 'Ada Lovelace',
            scopes: ['tools:read', 'tools:write'],
            extra: ['team_member_id' => 42],
        );

        $ctx = $principal->toToolContext(ip: '203.0.113.5', userAgent: 'mcp-client/1.0');

        $this->assertInstanceOf(ToolContext::class, $ctx);
        $this->assertSame('user:42', $ctx->principal);
        $this->assertSame('mcp', $ctx->channel);
        $this->assertSame(['tools:read', 'tools:write'], $ctx->scopes);
        $this->assertSame('203.0.113.5', $ctx->ip);
        $this->assertSame('mcp-client/1.0', $ctx->userAgent);
        $this->assertSame(['team_member_id' => 42], $ctx->extra);
    }

    public function testToToolContextAcceptsCustomChannel(): void
    {
        $principal = new Principal(id: 'user:1', displayName: 'Anon');

        $ctx = $principal->toToolContext(channel: 'web');

        $this->assertSame('web', $ctx->channel);
        $this->assertNull($ctx->ip);
        $this->assertNull($ctx->userAgent);
    }
}
