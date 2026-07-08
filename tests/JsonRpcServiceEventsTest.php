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

use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\McpServer\Events\McpRequestEvent;
use Milpa\McpServer\Events\McpRespondedEvent;
use Milpa\McpServer\JsonRpcService;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the 0.3 event retrofit: `mcp.request` (PRE, stoppable) / `mcp.responded` (POST,
 * readonly) around {@see JsonRpcService::handle()}'s method dispatch.
 *
 * A minimal in-process {@see MilpaEventDispatcherInterface} stand-in is used throughout —
 * these tests exercise `JsonRpcService`'s own emitter<->slot contract usage, not
 * `milpa/events`'s `EventDispatcher` implementation (that package isn't even a dependency of
 * this one; only the `milpa/core` interface and {@see InterceptionSlot} are — see the
 * `MilpaEventDispatcherInterfaceTestDispatcher` fixture below).
 */
class JsonRpcServiceEventsTest extends TestCase
{
    private ToolRegistry $toolRegistry;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->toolRegistry = new ToolRegistry($this->logger);
    }

    /**
     * With no dispatcher wired at all (the pre-0.3, still-default constructor arity), `handle()`
     * behaves byte-identically to 0.2 — no event machinery runs, nothing to observe.
     */
    public function testHandleWithoutDispatcherWorksExactlyAsBeforeZeroThree(): void
    {
        $service = new JsonRpcService($this->toolRegistry);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
        ]);

        $this->assertNotNull($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
    }

    /**
     * A listener that calls `$slot->shortCircuit($response)` on `mcp.request` serves its own
     * response in place of the method — the method body never runs (proven here via a
     * tool-call request where `tools/call` would otherwise execute a tool), and the
     * short-circuited value comes back as-is under `result`.
     */
    public function testShortCircuitingMcpRequestReturnsItsResponseWithoutRunningTheMethod(): void
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

        $canned = ['content' => [['type' => 'text', 'text' => 'served-from-cache']]];
        $dispatcher = new RecordingTestDispatcher();
        $dispatcher->on('mcp.request', function (string $event, array $payload) use ($canned): void {
            /** @var InterceptionSlot $slot */
            $slot = $payload['slot'];
            $slot->shortCircuit($canned);
        });

        $service = new JsonRpcService($this->toolRegistry, $dispatcher);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'side_effect_tool', 'arguments' => []],
            'id' => 1,
        ]);

        $this->assertFalse($executed, 'the tool callback must never run once mcp.request short-circuits');
        $this->assertNotNull($response);
        $this->assertArrayNotHasKey('error', $response);
        $this->assertSame($canned, $response['result']);
        $this->assertEquals(1, $response['id']);

        // mcp.responded still fired, carrying the short-circuited response — short-circuit is
        // not a blind spot for observability.
        $this->assertCount(1, $dispatcher->emissions['mcp.responded'] ?? []);
        /** @var McpRespondedEvent $respondedEvent */
        $respondedEvent = $dispatcher->emissions['mcp.responded'][0]['event'];
        $this->assertSame('tools/call', $respondedEvent->getMethod());
        $this->assertSame($canned, $respondedEvent->getResponse()['result']);
    }

    /**
     * A listener that calls the pure veto `$slot->stop()` (no result) on `mcp.request` stops
     * the method just as cleanly — it never runs — but since there's no replacement result,
     * the caller gets a well-formed `-32001` JSON-RPC error instead of a served response.
     */
    public function testVetoingMcpRequestStopsTheMethodCleanlyWithAnErrorResponse(): void
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

        $dispatcher = new RecordingTestDispatcher();
        $dispatcher->on('mcp.request', function (string $event, array $payload): void {
            /** @var InterceptionSlot $slot */
            $slot = $payload['slot'];
            $slot->stop();
        });

        $service = new JsonRpcService($this->toolRegistry, $dispatcher);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'side_effect_tool', 'arguments' => []],
            'id' => 2,
        ]);

        $this->assertFalse($executed, 'the tool callback must never run once mcp.request is vetoed');
        $this->assertNotNull($response);
        $this->assertArrayNotHasKey('result', $response);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(-32001, $response['error']['code']);
        $this->assertStringContainsString('tools/call', $response['error']['message']);
        $this->assertEquals(2, $response['id']);

        $this->assertCount(1, $dispatcher->emissions['mcp.responded'] ?? []);
    }

    /**
     * The un-intercepted happy path: `mcp.request` fires with the resolved method/params/ctx,
     * the method runs normally, and `mcp.responded` fires afterward with the real response —
     * proving the events are purely additive when nobody touches the slot.
     */
    public function testUninterceptedRequestDispatchesBothEventsAroundTheNormalResponse(): void
    {
        $this->toolRegistry->register(
            'echo_tool',
            'Echoes back input',
            ['type' => 'object', 'properties' => []],
            fn ($args) => 'Echo: ' . ($args['message'] ?? 'empty')
        );

        $dispatcher = new RecordingTestDispatcher();

        $service = new JsonRpcService($this->toolRegistry, $dispatcher);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'echo_tool', 'arguments' => ['message' => 'hi']],
            'id' => 3,
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);

        $this->assertCount(1, $dispatcher->emissions['mcp.request'] ?? []);
        /** @var McpRequestEvent $requestEvent */
        $requestEvent = $dispatcher->emissions['mcp.request'][0]['event'];
        $this->assertSame('tools/call', $requestEvent->getMethod());
        $this->assertSame(['name' => 'echo_tool', 'arguments' => ['message' => 'hi']], $requestEvent->getParams());
        $this->assertNull($requestEvent->getContext());

        $this->assertCount(1, $dispatcher->emissions['mcp.responded'] ?? []);
        /** @var McpRespondedEvent $respondedEvent */
        $respondedEvent = $dispatcher->emissions['mcp.responded'][0]['event'];
        $this->assertSame('tools/call', $respondedEvent->getMethod());
        $this->assertSame($response, $respondedEvent->getResponse());
    }

    /**
     * Notifications (no `id` member) still get both events dispatched — observability must not
     * go blind just because the wire response is suppressed — even though `handle()` itself
     * keeps returning `null` per the 0.2 contract.
     */
    public function testNotificationStillEmitsBothEventsEvenThoughHandleReturnsNull(): void
    {
        $this->toolRegistry->register(
            'side_effect_tool',
            'Records that it ran',
            ['type' => 'object', 'properties' => []],
            fn () => 'ran'
        );

        $dispatcher = new RecordingTestDispatcher();
        $service = new JsonRpcService($this->toolRegistry, $dispatcher);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'side_effect_tool', 'arguments' => []],
            // no "id" member -> notification
        ]);

        $this->assertNull($response);
        $this->assertCount(1, $dispatcher->emissions['mcp.request'] ?? []);
        $this->assertCount(1, $dispatcher->emissions['mcp.responded'] ?? []);
    }

    /**
     * A protocol-level failure (missing `method`) never reaches `mcp.request` at all — there is
     * no resolved method for a plugin to observe or veto. Pins the "brackets the dispatch, not
     * the whole of handle()" scoping documented on the class/method docblocks.
     */
    public function testProtocolLevelFailuresNeverEmitEvents(): void
    {
        $dispatcher = new RecordingTestDispatcher();
        $service = new JsonRpcService($this->toolRegistry, $dispatcher);

        $response = $service->handle([
            'jsonrpc' => '2.0',
            'id' => 1,
            // no "method" member
        ]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response);
        $this->assertArrayNotHasKey('mcp.request', $dispatcher->emissions);
        $this->assertArrayNotHasKey('mcp.responded', $dispatcher->emissions);
    }
}

/**
 * Minimal synchronous {@see MilpaEventDispatcherInterface} test fixture: records every
 * dispatched payload (keyed by event name) and invokes any handler registered via {@see on()}
 * inline. Deliberately not `milpa/events`'s real `EventDispatcher` — that package is not a
 * dependency of `milpa/mcp-server` (only the `milpa/core` interface and
 * {@see InterceptionSlot} are), so these tests exercise the interface contract directly, the
 * same way {@see \Milpa\ToolRuntime\Verification\HumanVerifier}'s own tests do upstream.
 */
final class RecordingTestDispatcher implements MilpaEventDispatcherInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $emissions = [];

    /** @var array<string, list<callable>> */
    private array $handlers = [];

    public function on(string $eventName, callable $handler): void
    {
        $this->handlers[$eventName][] = $handler;
    }

    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
        $this->emissions[$eventName][] = $payload;
        foreach ($this->handlers[$eventName] ?? [] as $handler) {
            $handler($eventName, $payload);
        }
    }

    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
        $this->on($eventName, $handler);
    }

    public function getSubscribers(string $eventName): array
    {
        return $this->handlers[$eventName] ?? [];
    }

    public function hasSubscribers(string $eventName): bool
    {
        return !empty($this->handlers[$eventName] ?? []);
    }
}
