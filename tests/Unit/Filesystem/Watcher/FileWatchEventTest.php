<?php

declare(strict_types=1);

namespace App\Tests\Unit\Filesystem\Watcher;

use App\Filesystem\Watcher\FileWatchEvent;
use PHPUnit\Framework\TestCase;

final class FileWatchEventTest extends TestCase
{
    public function testConstructorExposesAllFields(): void
    {
        $event = new FileWatchEvent(
            watchDescriptor: 7,
            path: 'song.mp3',
            fullPath: '/media/library/song.mp3',
            type: IN_MODIFY,
            isDirectory: false,
        );

        $this->assertSame(7, $event->watchDescriptor);
        $this->assertSame('song.mp3', $event->path);
        $this->assertSame('/media/library/song.mp3', $event->fullPath);
        $this->assertSame(IN_MODIFY, $event->type);
        $this->assertFalse($event->isDirectory);
    }

    public function testIsCreateDetectsCreateMask(): void
    {
        $event = new FileWatchEvent(1, 'a', '/a', IN_CREATE, false);

        $this->assertTrue($event->isCreate());
        $this->assertFalse($event->isDelete());
        $this->assertFalse($event->isModify());
        $this->assertFalse($event->isMove());
        $this->assertFalse($event->isCloseWrite());
    }

    public function testIsDeleteDetectsDeleteMask(): void
    {
        $event = new FileWatchEvent(1, 'a', '/a', IN_DELETE, false);

        $this->assertTrue($event->isDelete());
        $this->assertFalse($event->isCreate());
    }

    public function testIsModifyDetectsModifyMask(): void
    {
        $event = new FileWatchEvent(1, 'a', '/a', IN_MODIFY, false);

        $this->assertTrue($event->isModify());
    }

    public function testIsCloseWriteDetectsCloseWriteMask(): void
    {
        $event = new FileWatchEvent(1, 'a', '/a', IN_CLOSE_WRITE, false);

        $this->assertTrue($event->isCloseWrite());
    }

    public function testIsMoveDetectsMovedFromMask(): void
    {
        $event = new FileWatchEvent(1, 'a', '/a', IN_MOVED_FROM, false);

        $this->assertTrue($event->isMove());
    }

    public function testIsMoveDetectsMovedToMask(): void
    {
        $event = new FileWatchEvent(1, 'a', '/a', IN_MOVED_TO, false);

        $this->assertTrue($event->isMove());
    }

    public function testIsMoveReturnsFalseForNonMoveEvents(): void
    {
        $event = new FileWatchEvent(1, 'a', '/a', IN_CREATE, false);

        $this->assertFalse($event->isMove());
    }

    public function testCombinedCreateAndIsDirectoryMasksAreBothDetected(): void
    {
        // Real events often combine a type flag with IN_ISDIR.
        $event = new FileWatchEvent(2, 'newdir', '/media/newdir', IN_CREATE | IN_ISDIR, true);

        $this->assertTrue($event->isCreate());
        $this->assertTrue($event->isDirectory);
    }

    public function testEmptyTypeMatchesNothing(): void
    {
        $event = new FileWatchEvent(1, 'a', '/a', 0, false);

        $this->assertFalse($event->isCreate());
        $this->assertFalse($event->isDelete());
        $this->assertFalse($event->isModify());
        $this->assertFalse($event->isMove());
        $this->assertFalse($event->isCloseWrite());
    }
}
