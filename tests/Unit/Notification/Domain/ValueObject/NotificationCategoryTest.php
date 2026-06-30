<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Domain\ValueObject;

use App\Notification\Domain\Service\EventCategoryResolver;
use App\Notification\Domain\ValueObject\NotificationCategory;
use PHPUnit\Framework\TestCase;

final class NotificationCategoryTest extends TestCase
{
    public function testAdminOperationsCategoryExists(): void
    {
        $this->assertSame('admin_operations', NotificationCategory::AdminOperations->value);
    }

    public function testAdminOperationsHeaderColor(): void
    {
        $color = NotificationCategory::AdminOperations->headerColor();
        $this->assertIsString($color);
        $this->assertStringStartsWith('#', $color);
    }

    public function testAdminOperationsHeaderTitle(): void
    {
        $title = NotificationCategory::AdminOperations->headerTitle('Baander');
        $this->assertSame('Baander', $title);
    }

    public function testAllCategoriesHaveValues(): void
    {
        $cases = NotificationCategory::cases();
        $this->assertCount(4, $cases);

        $values = array_map(static fn(NotificationCategory $c) => $c->value, $cases);
        $this->assertContains('security', $values);
        $this->assertContains('background_jobs', $values);
        $this->assertContains('media_changes', $values);
        $this->assertContains('admin_operations', $values);
    }
}

final class EventCategoryResolverTest extends TestCase
{
    private EventCategoryResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EventCategoryResolver();
    }

    public function testResolveReturnsNullForUnknownEvent(): void
    {
        $this->assertNull($this->resolver->resolve('UnknownEvent'));
    }

    public function testResolveExistingEventsStillWork(): void
    {
        $this->assertSame(
            NotificationCategory::Security,
            $this->resolver->resolve(\App\Auth\Domain\Event\PasswordChanged::class),
        );

        $this->assertSame(
            NotificationCategory::BackgroundJobs,
            $this->resolver->resolve(\App\Library\Domain\Event\LibraryScanCompleted::class),
        );

        $this->assertSame(
            NotificationCategory::MediaChanges,
            $this->resolver->resolve(\App\Catalog\Domain\Event\AlbumCreated::class),
        );
    }
}
