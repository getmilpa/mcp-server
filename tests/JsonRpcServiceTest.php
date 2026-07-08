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
        $this->assertEquals('2025-06-18', $response['result']['protocolVersion']);
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

    /**
     * Pins the never-throw envelope contract (0.2, design item 2): an invalid `jsonrpc` member
     * used to throw a raw `\Exception('Invalid Request', -32600)` straight out of `handle()`,
     * pushing every transport into reimplementing the same error-array shape just to catch it.
     * It now comes back as a well-formed JSON-RPC error array, with the incoming `id` preserved.
     */
    public function testHandleInvalidJsonRpcVersionReturnsErrorArrayInsteadOfThrowing(): void
    {
        $request = [
            'jsonrpc' => '1.0',
            'method' => 'initialize',
            'id' => 1,
        ];

        $response = $this->service->handle($request);

        $this->assertNotNull($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32600, $response['error']['code']);
        $this->assertEquals('Invalid Request', $response['error']['message']);
    }

    /**
     * Same never-throw pin as above, for a missing `jsonrpc` member entirely (rather than a
     * wrong value). The request still carries an explicit `id`, so it is not a notification —
     * it must be answered, not suppressed.
     */
    public function testHandleMissingJsonRpcVersionReturnsErrorArrayInsteadOfThrowing(): void
    {
        $request = [
            'method' => 'initialize',
            'id' => 1,
        ];

        $response = $this->service->handle($request);

        $this->assertNotNull($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32600, $response['error']['code']);
        $this->assertEquals('Invalid Request', $response['error']['message']);
    }

    /**
     * Same never-throw pin as above, for a missing `method` member. Previously threw
     * `\Exception('Method not found', -32601)`; now a `-32601` error array with `id` preserved.
     */
    public function testHandleMissingMethodReturnsErrorArrayInsteadOfThrowing(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'id' => 1,
        ];

        $response = $this->service->handle($request);

        $this->assertNotNull($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32601, $response['error']['code']);
        $this->assertEquals('Method not found', $response['error']['message']);
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

    /**
     * Pins the 0.2 null-for-notifications contract (design item 1): a request with no `id`
     * member at all is a JSON-RPC notification (§4.1) — `handle()` now signals "write nothing"
     * by returning `null` itself, rather than silently building a full response envelope (as it
     * did pre-0.2, keyed on `$request['id'] ?? null`) and leaving suppression entirely to the
     * transport. This request was previously (and misleadingly) named `testHandleWithNullId`;
     * it has no `id` key at all, which is a notification, not an explicit `id: null` — see
     * {@see self::testHandleExplicitNullIdIsAnsweredNotSuppressed()} for that case.
     */
    public function testHandleRequestWithNoIdMemberIsANotificationAndReturnsNull(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [],
        ];

        $response = $this->service->handle($request);

        $this->assertNull($response);
    }

    /**
     * The companion pin to the notification test above: an explicit `{"id": null, ...}` member
     * IS a request per JSON-RPC 2.0 §4.1 and MUST be answered — `array_key_exists('id', ...)`
     * (not `isset()`) is what keeps this case from being misclassified as a notification and
     * silently swallowed.
     */
    public function testHandleExplicitNullIdIsAnsweredNotSuppressed(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [],
            'id' => null,
        ];

        $response = $this->service->handle($request);

        $this->assertNotNull($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertNull($response['id']);
        $this->assertArrayHasKey('result', $response);
    }

    /**
     * `notifications/initialized` is the canonical MCP notification — sent with no `id` member
     * by design. Previously `dispatch()` acknowledged it with a `new \stdClass()` result, wired
     * into a full response envelope by `handle()`'s `$request['id'] ?? null` fallback; a
     * transport had to separately inspect the raw request to know not to write that envelope to
     * the wire. It now comes back as `null` directly from `handle()`.
     */
    public function testHandleNotificationsInitializedReturnsNull(): void
    {
        $request = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ];

        $response = $this->service->handle($request);

        $this->assertNull($response);
    }

    /**
     * The null-for-notifications rule is general, not special-cased to
     * `notifications/initialized`: any method dispatched via a request with no `id` member
     * still executes (its side effects, if any, still happen — here, the tool actually runs)
     * but its result is suppressed. This is what lets `handle()` be the single source of truth
     * for "was this a notification", instead of every transport re-deriving it.
     */
    public function testHandleToolsCallAsNotificationStillExecutesButReturnsNull(): void
    {
        $executed = false;
        $this->toolRegistry->register(
            'side_effect_tool',
            'Records that it ran',
            ['type' => 'object', 'properties' => []],
            function () use (&$executed) {
                $executed = true;

                return 'ran';
            }
        );

        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'side_effect_tool',
                'arguments' => [],
            ],
            // No "id" member — this is a notification.
        ];

        $response = $this->service->handle($request);

        $this->assertNull($response);
        $this->assertTrue($executed, 'the tool callback must still run even though the response is suppressed');
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

    /**
     * Pins the 0.2 batch guard (design item 3): `handle()` is single-request only, and
     * `2025-06-18` dropped batching from the protocol entirely. A non-empty batch — the decoded
     * request is a plain list rather than a `{"jsonrpc": ...}` object — is refused with a
     * `-32600` error and `id: null` (there is no single id to key it on) instead of being
     * silently misread as a malformed single request.
     */
    public function testHandleNonEmptyBatchRequestReturnsInvalidRequestError(): void
    {
        $request = [
            ['jsonrpc' => '2.0', 'method' => 'initialize', 'id' => 1],
            ['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 2],
        ];

        $response = $this->service->handle($request);

        $this->assertNotNull($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertNull($response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32600, $response['error']['code']);
        $this->assertEquals('Batch requests are not supported', $response['error']['message']);
    }

    /**
     * Same batch guard as above, for the empty-array edge case. `array_is_list([])` is `true`
     * in PHP, and an empty batch is explicitly invalid per JSON-RPC 2.0 anyway ("an Array with
     * at least one value"), so `[]` gets the same `-32600` rather than being read as an
     * (also invalid, but differently-worded) empty request object.
     */
    public function testHandleEmptyBatchRequestReturnsInvalidRequestError(): void
    {
        $response = $this->service->handle([]);

        $this->assertNotNull($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertNull($response['id']);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32600, $response['error']['code']);
        $this->assertEquals('Batch requests are not supported', $response['error']['message']);
    }

    /**
     * Pins the "tool-level exceptions are already caught" half of design item 2:
     * {@see ToolRegistry::call()} wraps the tool callback in its own try/catch and returns a
     * `ToolResult::error(...)` — a callback that throws must never escape as an uncaught
     * exception through `JsonRpcService::handle()`, and the JSON-RPC envelope around it must
     * still be a normal successful `result` (the *tool* failed, not the *protocol* call).
     */
    public function testHandleToolsCallWhereCallbackThrowsIsSurfacedAsToolErrorNotException(): void
    {
        $this->toolRegistry->register(
            'exploding_tool',
            'Always throws',
            ['type' => 'object', 'properties' => []],
            function (): never {
                throw new \RuntimeException('boom');
            }
        );

        $request = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'exploding_tool',
                'arguments' => [],
            ],
            'id' => 11,
        ];

        $response = $this->service->handle($request);

        $this->assertNotNull($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(11, $response['id']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayNotHasKey('error', $response);
        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('boom', $decoded['error']);
    }
}
