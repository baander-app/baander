<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Configuration\ApplicationLayout;
use PHPUnit\Framework\TestCase;

final class ApplicationLayoutTest extends TestCase
{
    public function testCanonicalRootDerivesDependentPaths(): void
    {
        $layout = ApplicationLayout::load();
        self::assertSame(1, $layout->version);
        self::assertSame('/var/www/html', $layout->root);
        self::assertSame('/var/www/html/ui/web', $layout->path('ui/web'));
        self::assertSame('/opt/baander/public', ApplicationLayout::fromArray(['version' => 1, 'root' => '/opt/baander'])->path('public'));
    }

    public function testUnsafeOrUnknownLayoutIsRejected(): void
    {
        foreach ([['version' => 2, 'root' => '/app'], ['version' => 1, 'root' => '/'], ['version' => 1, 'root' => 'app'], ['version' => 1, 'root' => '/app/../etc'], ['version' => 1, 'root' => '/app', 'extra' => true]] as $data) {
            try {
                ApplicationLayout::fromArray($data);
                self::fail('Accepted unsafe layout');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testDependentPathCannotEscapeRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ApplicationLayout::load()->path('../etc');
    }
}
