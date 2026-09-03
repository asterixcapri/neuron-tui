<?php

declare(strict_types=1);

namespace NeuronTui\Tests\Storage;

use Generator;
use InvalidArgumentException;
use NeuronTui\Storage\FileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/neuron-tui-storage-'
            . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testReadingAMissingValueDoesNotCreateTheRoot(): void
    {
        $storage = new FileStorage($this->directory);

        self::assertNull($storage->read('sessions', 'missing'));
        self::assertDirectoryDoesNotExist($this->directory);
    }

    public function testNamespacesAreSeparateDirectories(): void
    {
        $storage = new FileStorage($this->directory);

        $storage->write('sessions', 'known.json', 'conversation');
        $storage->write('input-history', 'known.json', 'commands');

        self::assertSame(
            'conversation',
            file_get_contents($this->directory . '/sessions/known.json'),
        );
        self::assertSame(
            'commands',
            file_get_contents(
                $this->directory . '/input-history/known.json',
            ),
        );
        self::assertSame(
            'conversation',
            $storage->read('sessions', 'known.json'),
        );
        self::assertSame(
            'commands',
            $storage->read('input-history', 'known.json'),
        );
    }

    public function testWritingAnExistingValueAtomicallyReplacesItsFile(): void
    {
        $storage = new FileStorage($this->directory);
        $storage->write('sessions', 'known.json', 'before');

        $storage->write('sessions', 'known.json', 'after');

        self::assertSame('after', $storage->read('sessions', 'known.json'));
        self::assertSame(
            ['known.json'],
            array_values(array_diff(
                scandir($this->directory . '/sessions') ?: [],
                ['.', '..'],
            )),
        );
    }

    #[DataProvider('unsafeComponents')]
    public function testUnsafeNamespacesAreRejected(string $component): void
    {
        $storage = new FileStorage($this->directory);

        $this->expectException(InvalidArgumentException::class);

        $storage->write($component, 'key', 'value');
    }

    #[DataProvider('unsafeComponents')]
    public function testUnsafeKeysAreRejected(string $component): void
    {
        $storage = new FileStorage($this->directory);

        $this->expectException(InvalidArgumentException::class);

        $storage->write('namespace', $component, 'value');
    }

    /**
     * @return Generator<string, array{string}>
     */
    public static function unsafeComponents(): Generator
    {
        yield 'empty' => [''];
        yield 'current directory' => ['.'];
        yield 'parent directory' => ['..'];
        yield 'traversal' => ['../outside'];
        yield 'embedded traversal' => ['safe/../../outside'];
        yield 'absolute path' => ['/outside'];
        yield 'backslash traversal' => ['..\\outside'];
        yield 'backslash separator' => ['safe\\outside'];
        yield 'null byte' => ["safe\0outside"];
    }

    public function testDotsThatDoNotNameADirectoryBoundaryRemainValid(): void
    {
        $storage = new FileStorage($this->directory);

        $storage->write('..sessions', 'history..json', 'value');

        self::assertSame(
            'value',
            $storage->read('..sessions', 'history..json'),
        );
    }

    public function testASymbolicLinkCannotRedirectANamespaceOutsideTheRoot(): void
    {
        $outside = $this->directory . '-outside';
        mkdir($this->directory);
        mkdir($outside);
        symlink($outside, $this->directory . '/sessions');

        try {
            $this->expectException(RuntimeException::class);
            (new FileStorage($this->directory))->write(
                'sessions',
                'known',
                'value',
            );
        } finally {
            unlink($this->directory . '/sessions');
            rmdir($outside);
        }
    }

    public function testASymbolicLinkCannotRedirectAKeyOutsideTheRoot(): void
    {
        $outside = $this->directory . '-outside';
        mkdir($this->directory . '/sessions', 0777, true);
        file_put_contents($outside, 'untouched');
        symlink($outside, $this->directory . '/sessions/known');

        try {
            $this->expectException(RuntimeException::class);
            (new FileStorage($this->directory))->write(
                'sessions',
                'known',
                'replacement',
            );
        } finally {
            unlink($this->directory . '/sessions/known');
            unlink($outside);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path)
                ? $this->removeDirectory($path)
                : unlink($path);
        }

        rmdir($directory);
    }
}
