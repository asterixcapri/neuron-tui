<?php

declare(strict_types=1);

namespace NeuronTui\Tests;

use NeuronTui\Tui;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PackageIdentityTest extends TestCase
{
    public function testComposerPublishesOnlyTheNeuronTuiIdentity(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);
        self::assertArrayHasKey('name', $composer);
        self::assertArrayHasKey('description', $composer);
        self::assertArrayHasKey('autoload', $composer);
        self::assertIsArray($composer['autoload']);
        self::assertArrayHasKey('psr-4', $composer['autoload']);
        self::assertIsArray($composer['autoload']['psr-4']);
        self::assertSame('asterixcapri/neuron-tui', $composer['name']);
        self::assertSame(
            'Reusable terminal user interface for conversations with Neuron AI Agents.',
            $composer['description'],
        );
        self::assertSame(['NeuronTui\\' => 'src/'], $composer['autoload']['psr-4']);
        self::assertArrayNotHasKey('NeuronCli\\', $composer['autoload']['psr-4']);
    }

    public function testOnlyTheNewFinalEntryPointAutoloads(): void
    {
        self::assertTrue(class_exists(Tui::class));
        self::assertTrue((new ReflectionClass(Tui::class))->isFinal());
        self::assertFalse(class_exists('NeuronCli\\NeuronCli'));
        self::assertFalse(class_exists('NeuronCli\\Tui'));
    }
}
