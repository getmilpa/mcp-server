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

namespace Milpa\McpServer\Tests;

use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\McpServer\Events\McpServerEvents;
use Milpa\McpServer\JsonRpcService;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Falsifier for greenhouse decisions/0228: the emitter declares every event it dispatches, to
 * the dispatcher, from the same constants the dispatch sites use.
 *
 * The real code path — {@see JsonRpcService} constructed with a dispatcher, one JSON-RPC request
 * handled — is driven with a SPY that implements both {@see MilpaEventDispatcherInterface} and
 * {@see DeclaredEvents} and records what was declared and what was dispatched. Then: nothing
 * dispatched went undeclared, the declared set is exactly the list pinned here (a deleted or
 * renamed declaration goes red), each declaration's subject matches the dispatched payload, and
 * — the control — a dispatcher that cannot hold declarations still runs the same path unharmed.
 */
final class TheEmitterDeclaresEveryEventItDispatchesTest extends TestCase
{
    /**
     * The exact names this package dispatches. Hardcoded on purpose: the test must not read the
     * expected list from the class under test.
     */
    private const EXPECTED_NAMES = ['mcp.request', 'mcp.responded'];

    private ToolRegistry $toolRegistry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolRegistry = new ToolRegistry($this->createMock(LoggerInterface::class));
    }

    /**
     * Handling one `tools/list` request with the spy: every dispatched name was declared, the
     * declared set is exactly {@see self::EXPECTED_NAMES}, and each declaration's `subjectKey`,
     * `subjectType`, `dispatchedBy` and `interceptable` match the payload the spy really saw.
     */
    public function testEveryDispatchedEventWasDeclaredWithTheSubjectItReallyCarries(): void
    {
        $spy = $this->spy();
        $service = new JsonRpcService($this->toolRegistry, $spy);

        $response = $service->handle(['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response, 'the real code path must have run');

        // (b) the declared set is exactly the pinned list, in declaration order.
        $declaredNames = array_map(static fn (EventDeclaration $d): string => $d->name, $spy->declared());
        $this->assertSame(self::EXPECTED_NAMES, $declaredNames);

        // (a) no undeclared dispatch — and the path dispatched something, so (a) is not vacuous.
        $this->assertNotEmpty($spy->dispatched(), 'the code path must dispatch, or the check proves nothing');
        $this->assertSame([], array_values(array_diff($spy->dispatched(), $declaredNames)), 'dispatched without a declaration');
        $this->assertSame([], array_values(array_diff($declaredNames, $spy->dispatched())), 'declared but never dispatched on this path');

        // (c) each declaration describes the payload that was really dispatched under that name.
        foreach ($spy->declared() as $declaration) {
            $payload = $spy->payloads[$declaration->name][0];
            $this->assertArrayHasKey($declaration->subjectKey, $payload, $declaration->name);
            $this->assertNotNull($declaration->subjectType, $declaration->name);
            $this->assertInstanceOf($declaration->subjectType, $payload[$declaration->subjectKey], $declaration->name);
            $this->assertSame(JsonRpcService::class, $declaration->dispatchedBy, $declaration->name);
            $this->assertFalse($declaration->mutable, $declaration->name . ' carries a readonly VO');
            $this->assertSame(
                $declaration->interceptable,
                ($payload['slot'] ?? null) instanceof InterceptionSlot,
                $declaration->name . ': interceptable must mean a slot travels in the payload',
            );
        }
    }

    /**
     * The declarations are built from the same constants the dispatch sites use: a rename of the
     * constant moves both, a retyped string cannot.
     */
    public function testDeclarationsAreBuiltFromTheConstantsTheDispatchSitesUse(): void
    {
        $names = array_map(static fn (EventDeclaration $d): string => $d->name, McpServerEvents::declarations());

        $this->assertSame([McpServerEvents::REQUEST, McpServerEvents::RESPONDED], $names);
        $this->assertSame(self::EXPECTED_NAMES, $names);
    }

    /**
     * CONTROL: a dispatcher that does NOT implement {@see DeclaredEvents} runs the same code path
     * without error — nothing is declared to it, and both events still dispatch.
     */
    public function testADispatcherThatCannotHoldDeclarationsIsAskedNothingAndStillReceivesEveryDispatch(): void
    {
        $plain = new class () implements MilpaEventDispatcherInterface {
            /** @var list<string> */
            public array $dispatched = [];

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->dispatched[] = $eventName;
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };

        $service = new JsonRpcService($this->toolRegistry, $plain);

        $response = $service->handle(['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1]);

        $this->assertNotNull($response);
        $this->assertArrayHasKey('result', $response);
        $this->assertSame(self::EXPECTED_NAMES, $plain->dispatched);
    }

    /**
     * A spy that is both a dispatcher and a holder of declarations: records every declared name
     * (first declaration of a name wins, as the contract says) and every dispatched payload.
     *
     * @return MilpaEventDispatcherInterface&DeclaredEvents&object{payloads: array<string, list<array<string, mixed>>>}
     */
    private function spy(): MilpaEventDispatcherInterface&DeclaredEvents
    {
        return new class () implements MilpaEventDispatcherInterface, DeclaredEvents {
            /** @var array<string, EventDeclaration> */
            private array $declarations = [];

            /** @var array<string, list<array<string, mixed>>> */
            public array $payloads = [];

            public function declare(EventDeclaration ...$events): void
            {
                foreach ($events as $event) {
                    $this->declarations[$event->name] ??= $event;
                }
            }

            public function declared(): array
            {
                return array_values($this->declarations);
            }

            public function dispatched(): array
            {
                return array_keys($this->payloads);
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->payloads[$eventName][] = $payload;
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };
    }
}
