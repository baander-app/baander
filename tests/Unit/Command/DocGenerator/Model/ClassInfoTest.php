<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\DocGenerator\Model;

use App\Command\DocGenerator\Model\ClassInfo;
use PHPUnit\Framework\TestCase;

final class ClassInfoTest extends TestCase
{
    public function testConstructorWithRequiredArgumentsDefaultsEverythingElse(): void
    {
        $info = new ClassInfo(
            fqcn: 'App\Auth\Domain\Model\User',
            shortName: 'User',
            namespace: 'App\Auth\Domain\Model',
            layer: 'Domain',
        );

        $this->assertSame('App\Auth\Domain\Model\User', $info->fqcn);
        $this->assertSame('User', $info->shortName);
        $this->assertSame('App\Auth\Domain\Model', $info->namespace);
        $this->assertSame('Domain', $info->layer);
        $this->assertSame('', $info->description);
        $this->assertSame([], $info->interfaces);
        $this->assertSame([], $info->properties);
        $this->assertFalse($info->isAggregateRoot);
        $this->assertFalse($info->isValueObject);
        $this->assertFalse($info->isEnum);
    }

    public function testConstructorAssignsAllProvidedValues(): void
    {
        $info = new ClassInfo(
            fqcn: 'App\Catalog\Domain\Model\Album',
            shortName: 'Album',
            namespace: 'App\Catalog\Domain\Model',
            layer: 'Domain',
            description: 'The album aggregate root.',
            interfaces: ['RepositoryInterface', 'Stringable'],
            properties: ['id', 'title', 'artist'],
            isAggregateRoot: true,
            isValueObject: false,
            isEnum: false,
        );

        $this->assertSame('App\Catalog\Domain\Model\Album', $info->fqcn);
        $this->assertSame('The album aggregate root.', $info->description);
        $this->assertSame(['RepositoryInterface', 'Stringable'], $info->interfaces);
        $this->assertSame(['id', 'title', 'artist'], $info->properties);
        $this->assertTrue($info->isAggregateRoot);
        $this->assertFalse($info->isValueObject);
        $this->assertFalse($info->isEnum);
    }

    public function testFlagsCanBeSetIndependentlyForValueObject(): void
    {
        $info = new ClassInfo(
            fqcn: 'App\Auth\Domain\ValueObject\Email',
            shortName: 'Email',
            namespace: 'App\Auth\Domain\ValueObject',
            layer: 'Domain',
            isValueObject: true,
        );

        $this->assertTrue($info->isValueObject);
        $this->assertFalse($info->isAggregateRoot);
        $this->assertFalse($info->isEnum);
    }

    public function testFlagsCanBeSetIndependentlyForEnum(): void
    {
        $info = new ClassInfo(
            fqcn: 'App\Activity\Domain\Model\ActivityType',
            shortName: 'ActivityType',
            namespace: 'App\Activity\Domain\Model',
            layer: 'Domain',
            isEnum: true,
        );

        $this->assertTrue($info->isEnum);
        $this->assertFalse($info->isAggregateRoot);
        $this->assertFalse($info->isValueObject);
    }
}
