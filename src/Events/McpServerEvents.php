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

namespace Milpa\McpServer\Events;

use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\McpServer\JsonRpcService;

/**
 * Every event this package dispatches, declared by the emitter itself (greenhouse decisions/0228).
 *
 * The constants are the names {@see JsonRpcService::handle()} hands to `dispatch()` — the
 * dispatch sites and {@see self::declarations()} read the SAME constant, so a renamed event
 * cannot drift between what fires and what is declared. `JsonRpcService` declares these to any
 * dispatcher implementing {@see \Milpa\Interfaces\Event\DeclaredEvents} the moment it receives
 * one; a dispatcher that does not implement it is asked nothing, and dispatching keeps working
 * whether or not anything was declared.
 */
final class McpServerEvents
{
    /**
     * PRE, interceptable: fires right before a resolved JSON-RPC method runs, with an
     * {@see \Milpa\Events\InterceptionSlot} under `slot` a listener may stop or short-circuit.
     */
    public const REQUEST = 'mcp.request';

    /**
     * POST, readonly: fires once a JSON-RPC response envelope exists for a resolved method.
     */
    public const RESPONDED = 'mcp.responded';

    /**
     * One declaration per event name this package dispatches, in the order they fire.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new EventDeclaration(
                name: self::REQUEST,
                dispatchedBy: JsonRpcService::class,
                when: 'Right before a resolved JSON-RPC method runs; a listener may veto or short-circuit it through the slot.',
                subjectKey: 'event',
                subjectType: McpRequestEvent::class,
                mutable: false,
                interceptable: true,
            ),
            new EventDeclaration(
                name: self::RESPONDED,
                dispatchedBy: JsonRpcService::class,
                when: 'Once a JSON-RPC response envelope exists for a resolved method, whether it ran, was short-circuited or was vetoed.',
                subjectKey: 'event',
                subjectType: McpRespondedEvent::class,
                mutable: false,
                interceptable: false,
            ),
        ];
    }
}
