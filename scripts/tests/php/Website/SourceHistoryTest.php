<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests\Website;

use Baander\Tooling\Website\SourceHistory;
use PHPUnit\Framework\TestCase;

final class SourceHistoryTest extends TestCase
{
    private const string LOG = "\x1e2026-11-04T17:00:00+01:00\n\ndocs-book/guide.md\nui/site/assets/screenshots/music.png\n"
        . "\x1e2026-11-02T09:30:00+00:00\n\ndocs-book/guide.md\ndocs-book/other.md\nui/site/templates/home.php\n";

    public function testUsesTheNewestCommitForEachPath(): void
    {
        $history = SourceHistory::fromGitLog(self::LOG);
        self::assertSame('2026-11-04T17:00:00+01:00', $history->lastModified(['docs-book/guide.md']));
        self::assertSame('2026-11-02T09:30:00+00:00', $history->lastModified(['docs-book/other.md']));
    }

    public function testDirectoriesAndPathListsTakeTheNewestMatchingFile(): void
    {
        $history = SourceHistory::fromGitLog(self::LOG);
        self::assertSame('2026-11-04T17:00:00+01:00', $history->lastModified(['ui/site/templates/home.php', 'ui/site/assets/screenshots']));
        self::assertSame('2026-11-02T09:30:00+00:00', $history->lastModified(['ui/site/templates/']));
        self::assertNull($history->lastModified(['ui/site/assets/screen']), 'A directory matches only whole path segments');
        self::assertNull($history->lastModified(['docs-book/untracked.md']));
        self::assertNull(SourceHistory::fromGitLog('')->lastModified(['docs-book']));
    }

    public function testRejectsOutputThatIsNotTheExpectedLogFormat(): void
    {
        $this->expectException(\RuntimeException::class);
        SourceHistory::fromGitLog("\x1ecommit abc123\ndocs-book/guide.md\n");
    }
}
