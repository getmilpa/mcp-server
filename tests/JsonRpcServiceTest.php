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

namespace Milpa\McpServer\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\McpServer\JsonRpcService;

class JsonRpcServiceTest extends TestCase
{
    private ToolRegistry $toolRegistry;
    private JsonRpcService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->toolRegistry = new ToolRegistry($this->logger);
        $this->service = new JsonRpcService($this->toolRegistry);
    }

    public function testHandleInitialize(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [],
            'id' => 1,
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('result', $response);
        $this->assertEquals('2025-03-26', $response['result']['protocolVersion']);
        $this->assertEquals('Milpa MCP Server', $response['result']['serverInfo']['name']);
        $this->assertEquals('1.0.0', $response['result']['serverInfo']['version']);
    }

    public function testHandleToolsList(): void
    {
        // Register a test tool
        $this->toolRegistry->register(
            'test_tool',
            'A test tool',
            ['type' => 'object', 'properties' => []],
            fn () => 'result'
        );

        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 2,
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(2, $response['id']);
        $this->assertArrayHasKey('tools', $response['result']);
        $this->assertCount(1, $response['result']['tools']);
    }

    public function testHandleToolsCall(): void
    {
        $this->toolRegistry->register(
            'echo_tool',
            'Echoes back input',
            ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
            fn ($args) => 'Echo: ' . ($args['message'] ?? 'empty')
        );

        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo_tool',
                'arguments' => ['message' => 'Hello'],
            ],
            'id' => 3,
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(3, $response['id']);
        $this->assertArrayHasKey('content', $response['result']);
        $this->assertEquals('text', $response['result']['content'][0]['type']);
        // The result is wrapped in a ToolResult, so it's JSON encoded
        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertNotNull($decoded);
        $this->assertTrue($decoded['success']);
        $this->assertEquals('Echo: Hello', $decoded['data']);
    }

    public function testHandleToolsCallWithObjectResult(): void
    {
        $this->toolRegistry->register(
            'object_tool',
            'Returns an object',
            ['type' => 'object', 'properties' => []],
            fn () => ['key' => 'value', 'number' => 42]
        );

        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'object_tool',
                'arguments' => [],
            ],
            'id' => 4,
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('content', $response['result']);
        // Result is JSON encoded ToolResult
        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertNotNull($decoded);
        $this->assertTrue($decoded['success']);
        $this->assertEquals('value', $decoded['data']['key']);
        $this->assertEquals(42, $decoded['data']['number']);
    }

    public function testHandleInvalidJsonRpcVersion(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid Request');

        $request = [
            'jsonrpc' => '1.0',
            'method' => 'initialize',
            'id' => 1,
        ];

        $this->service->handle($request);
    }

    public function testHandleMissingJsonRpcVersion(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid Request');

        $request = [
            'method' => 'initialize',
            'id' => 1,
        ];

        $this->service->handle($request);
    }

    public function testHandleMissingMethod(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Method not found');

        $request = [
            'jsonrpc' => '2.0',
            'id' => 1,
        ];

        $this->service->handle($request);
    }

    public function testHandleUnknownMethod(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'unknown/method',
            'id' => 5,
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(5, $response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32601, $response['error']['code']);
        $this->assertStringContainsString('Method not found', $response['error']['message']);
    }

    public function testHandleToolNotFound(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'nonexistent_tool',
                'arguments' => [],
            ],
            'id' => 6,
        ];

        $response = $this->service->handle($request);

        // The response should contain the tool result with error
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(6, $response['id']);
        $this->assertArrayHasKey('content', $response['result']);
        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('not found', $decoded['error']);
    }

    public function testHandleWithNullId(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [],
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertNull($response['id']);
        $this->assertArrayHasKey('result', $response);
    }

    public function testHandleToolsListEmpty(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 7,
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('tools', $response['result']);
        $this->assertEmpty($response['result']['tools']);
    }

    public function testInitializeCapabilities(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => ['client' => 'test'],
            'id' => 8,
        ];

        $response = $this->service->handle($request);

        $this->assertArrayHasKey('capabilities', $response['result']);
        $this->assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    /**
     * Pins a level-6-PHPStan-driven fix made while extracting this class into
     * milpa/mcp-server: `callTool()` used to pass an unchecked `$params['name'] ?? null`
     * straight into `ToolRegistry::call(string $name, ...)`, which — under
     * `declare(strict_types=1)` — throws an uncaught `TypeError` for a missing `name`
     * instead of a clean JSON-RPC error. It now returns a `-32602 Invalid params` error.
     */
    public function testHandleToolsCallMissingNameReturnsInvalidParamsError(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'arguments' => [],
            ],
            'id' => 9,
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(9, $response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32602, $response['error']['code']);
        $this->assertStringContainsString('Invalid params', $response['error']['message']);
    }

    public function testHandleToolsCallWithNonArrayArgumentsIsTreatedAsEmpty(): void
    {
        $this->toolRegistry->register(
            'no_args_tool',
            'Reports which non-internal argument keys it received',
            ['type' => 'object', 'properties' => []],
            // ToolRegistry::call() injects its own '_ctx' key into $args before invoking the
            // callback, so exclude it to isolate what the caller-supplied arguments normalized to.
            fn ($args) => array_keys(array_diff_key($args, ['_ctx' => null]))
        );

        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'no_args_tool',
                'arguments' => 'not-an-array',
            ],
            'id' => 10,
        ];

        $response = $this->service->handle($request);

        $this->assertEquals('2.0', $response['jsonrpc']);
        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertTrue($decoded['success']);
        $this->assertEquals([], $decoded['data']);
    }
}
