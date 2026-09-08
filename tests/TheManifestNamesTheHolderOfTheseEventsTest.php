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

use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\McpServer\Events\McpServerEvents;
use PHPUnit\Framework\TestCase;

/**
 * Falsifier for greenhouse decisions/0228, second slice: this package names its declarations
 * holder in its own manifest, so a host can read the events without constructing any emitter.
 *
 * The manifest is read from DISK — the real `composer.json` this repository ships, the same file
 * Composer copies into `vendor/composer/installed.json` for the host to resolve. The test never
 * asks the code what the manifest ought to say: it asks the manifest, then holds what it found
 * against the class. A typo in the string, a rename that forgets the manifest, or a holder that
 * stops implementing {@see DeclaresEvents} all go red here.
 */
final class TheManifestNamesTheHolderOfTheseEventsTest extends TestCase
{
    /**
     * The holder's fully-qualified name as it must appear in the manifest, typed out on purpose:
     * `::class` would follow a rename and hide exactly the drift this test exists to catch.
     */
    private const HOLDER_IN_THE_MANIFEST = 'Milpa\McpServer\Events\McpServerEvents';

    /**
     * `extra.milpa.events` names exactly this package's holder, and the sibling `capability` key
     * the manifest already carried is still there beside it.
     */
    public function testTheManifestListsExactlyTheHolderOfThisPackagesEvents(): void
    {
        $milpa = $this->manifestMilpaSection();

        $this->assertArrayHasKey('events', $milpa, 'composer.json must declare extra.milpa.events');
        $this->assertSame([self::HOLDER_IN_THE_MANIFEST], $milpa['events']);
        $this->assertSame([McpServerEvents::class], $milpa['events'], 'the manifest string must be the holder that exists');
        $this->assertArrayHasKey('capability', $milpa, 'declaring events must not displace the capability manifest');
    }

    /**
     * Every class named in the manifest exists and is a holder — a host that resolves the name
     * gets a class it can call `declarations()` on, never a missing or unrelated class.
     */
    public function testEveryClassNamedInTheManifestExistsAndIsAHolder(): void
    {
        $names = $this->eventHolderNamesFromTheManifest();

        $this->assertNotEmpty($names);

        foreach ($names as $name) {
            $this->assertTrue(class_exists($name), $name . ' is named in the manifest but does not exist');
            $this->assertTrue(is_a($name, DeclaresEvents::class, true), $name . ' is named in the manifest but is not a ' . DeclaresEvents::class);
        }
    }

    /**
     * Calling `declarations()` on the class named IN THE MANIFEST yields the same event names as
     * the holder itself — the manifest is a route to the real declarations, not a second copy.
     */
    public function testTheClassNamedInTheManifestDeclaresTheSameEventsAsTheHolder(): void
    {
        $fromTheManifest = [];
        foreach ($this->eventHolderNamesFromTheManifest() as $name) {
            /** @var class-string<DeclaresEvents> $name */
            foreach ($name::declarations() as $declaration) {
                $fromTheManifest[] = $declaration->name;
            }
        }

        $fromTheHolder = array_map(
            static fn (EventDeclaration $declaration): string => $declaration->name,
            McpServerEvents::declarations(),
        );

        sort($fromTheManifest);
        sort($fromTheHolder);

        $this->assertNotEmpty($fromTheHolder, 'the holder must declare something, or this check proves nothing');
        $this->assertSame($fromTheHolder, $fromTheManifest);
    }

    /**
     * The holder class names listed under `extra.milpa.events` in the manifest on disk.
     *
     * @return list<string>
     */
    private function eventHolderNamesFromTheManifest(): array
    {
        $events = $this->manifestMilpaSection()['events'] ?? null;

        $this->assertIsArray($events, 'extra.milpa.events must be a list of holder class names');

        $names = [];
        foreach ($events as $name) {
            $this->assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    /**
     * The `extra.milpa` object of this package's own composer.json, read from disk.
     *
     * @return array<string, mixed>
     */
    private function manifestMilpaSection(): array
    {
        $path = dirname(__DIR__) . '/composer.json';
        $raw = file_get_contents($path);

        $this->assertIsString($raw, 'cannot read ' . $path);

        $manifest = json_decode($raw, true);

        $this->assertIsArray($manifest, 'composer.json is not valid JSON');
        $this->assertArrayHasKey('extra', $manifest);
        $this->assertIsArray($manifest['extra']);
        $this->assertArrayHasKey('milpa', $manifest['extra']);
        $this->assertIsArray($manifest['extra']['milpa']);

        /** @var array<string, mixed> $milpa */
        $milpa = $manifest['extra']['milpa'];

        return $milpa;
    }
}
