<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\DocGenerator;

use App\Command\DocGenerator\PhpDocLinkBuilder;
use PHPUnit\Framework\TestCase;

final class PhpDocLinkBuilderTest extends TestCase
{
    private PhpDocLinkBuilder $builder;

    private const string PHPDOC_DIR = 'phpdoc';

    protected function setUp(): void
    {
        $this->builder = new PhpDocLinkBuilder();
    }

    public function testClassLinkAtRootDepthUsesMixedCaseSlug(): void
    {
        // Backslashes become dashes; case is preserved.
        $link = $this->builder->classLink('App\Auth\Domain\Model\User', '', self::PHPDOC_DIR);

        $this->assertSame('phpdoc/classes/App-Auth-Domain-Model-User.html', $link);
    }

    public function testClassLinkAddsOneLevelOfParentTraversal(): void
    {
        $link = $this->builder->classLink('App\Auth\User', 'contexts/auth.html', self::PHPDOC_DIR);

        $this->assertSame('../phpdoc/classes/App-Auth-User.html', $link);
    }

    public function testClassLinkAddsTwoLevelsOfParentTraversal(): void
    {
        $link = $this->builder->classLink('App\Auth\User', 'contexts/nested/auth.html', self::PHPDOC_DIR);

        $this->assertSame('../../phpdoc/classes/App-Auth-User.html', $link);
    }

    public function testNamespaceLinkLowercasesTheSlug(): void
    {
        $link = $this->builder->namespaceLink('App\Auth\Domain\Model', '', self::PHPDOC_DIR);

        $this->assertSame('phpdoc/namespaces/app-auth-domain-model.html', $link);
    }

    public function testNamespaceLinkRespectsPageDepth(): void
    {
        $link = $this->builder->namespaceLink('App\Catalog\Domain', 'contexts/catalog.html', self::PHPDOC_DIR);

        $this->assertSame('../phpdoc/namespaces/app-catalog-domain.html', $link);
    }

    public function testIndexLinkAtRootDepth(): void
    {
        $link = $this->builder->indexLink('', self::PHPDOC_DIR);

        $this->assertSame('phpdoc/index.html', $link);
    }

    public function testIndexLinkRespectsPageDepth(): void
    {
        $link = $this->builder->indexLink('contexts/deep/auth.html', self::PHPDOC_DIR);

        $this->assertSame('../../phpdoc/index.html', $link);
    }

    public function testLinksUseTheConfiguredPhpdocDirectory(): void
    {
        $classLink = $this->builder->classLink('App\Auth\User', '', '.phpdoc/build');
        $namespaceLink = $this->builder->namespaceLink('App\Auth\User', '', '.phpdoc/build');
        $indexLink = $this->builder->indexLink('', '.phpdoc/build');

        $this->assertSame('.phpdoc/build/classes/App-Auth-User.html', $classLink);
        $this->assertSame('.phpdoc/build/namespaces/app-auth-user.html', $namespaceLink);
        $this->assertSame('.phpdoc/build/index.html', $indexLink);
    }

    public function testClassLinkAndNamespaceLinkDifferOnlyInCaseAndPath(): void
    {
        $fqcn = 'App\Auth\Domain\Model\User';
        $classLink = $this->builder->classLink($fqcn, '', self::PHPDOC_DIR);
        $namespaceLink = $this->builder->namespaceLink($fqcn, '', self::PHPDOC_DIR);

        // Class page keeps case; namespace page is lowercased.
        $this->assertStringContainsString('App-Auth-Domain-Model-User', $classLink);
        $this->assertStringContainsString('app-auth-domain-model-user', $namespaceLink);
        $this->assertNotSame($classLink, $namespaceLink);
    }
}
