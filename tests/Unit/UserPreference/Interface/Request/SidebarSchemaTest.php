<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Interface\Request;

use App\UserPreference\Interface\Request\UpdateSidebarSectionsRequest;
use OpenApi\Attributes as OA;
use PHPUnit\Framework\TestCase;

final class SidebarSchemaTest extends TestCase
{
    public function testNestedArraySchemaCanBeInstantiated(): void
    {
        $attributes = (new \ReflectionClass(UpdateSidebarSectionsRequest::class))->getAttributes(OA\Schema::class);
        $schema = $attributes[0]->newInstance();

        self::assertSame('sections', $schema->properties[0]->property);
        self::assertInstanceOf(OA\Items::class, $schema->properties[0]->items);
        self::assertSame('items', $schema->properties[0]->items->properties[3]->property);
        self::assertInstanceOf(OA\Items::class, $schema->properties[0]->items->properties[3]->items);
    }
}
