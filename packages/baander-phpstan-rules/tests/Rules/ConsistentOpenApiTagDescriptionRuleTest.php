<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Rules;

use Baander\PHPStan\Rules\ConsistentOpenApiTagDescriptionRule;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PHPUnit\Framework\TestCase;
use PHPStan\Analyser\Scope;

final class ConsistentOpenApiTagDescriptionRuleTest extends TestCase
{
    private ConsistentOpenApiTagDescriptionRule $rule;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(ConsistentOpenApiTagDescriptionRule::class);
        $prop = $ref->getProperty('tags');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $this->rule = new ConsistentOpenApiTagDescriptionRule();
    }

    public function testNodeTypeIsClass(): void
    {
        $this->assertSame(Class_::class, $this->rule->getNodeType());
    }

    public function testNoErrorsWithoutOATagAttribute(): void
    {
        $class = $this->createClassWithName('SomeController', [
            new AttributeGroup([
                new Attribute(new Name('Route'), [new Arg(new String_('/api/test'), name: new Identifier('path'))]),
            ]),
        ]);
        $scope = $this->createMock(Scope::class);
        $scope->method('getFile')->willReturn('/some/file.php');

        $errors = $this->rule->processNode($class, $scope);

        $this->assertSame([], $errors);
    }

    public function testNoErrorForSingleTag(): void
    {
        $class = $this->createClassWithTag('Auth', 'Auth endpoints');
        $scope = $this->createMock(Scope::class);
        $scope->method('getFile')->willReturn('/src/AuthController.php');

        $errors = $this->rule->processNode($class, $scope);

        $this->assertSame([], $errors);
    }

    public function testNoErrorForConsistentDescriptions(): void
    {
        $scope1 = $this->createMock(Scope::class);
        $scope1->method('getFile')->willReturn('/src/AuthController.php');
        $scope2 = $this->createMock(Scope::class);
        $scope2->method('getFile')->willReturn('/src/LoginController.php');

        $this->rule->processNode($this->createClassWithTag('Auth', 'Auth endpoints'), $scope1);
        $errors = $this->rule->processNode($this->createClassWithTag('Auth', 'Auth endpoints'), $scope2);

        $this->assertSame([], $errors);
    }

    public function testErrorForConflictingDescriptions(): void
    {
        $scope1 = $this->createMock(Scope::class);
        $scope1->method('getFile')->willReturn('/src/AuthController.php');
        $scope2 = $this->createMock(Scope::class);
        $scope2->method('getFile')->willReturn('/src/LoginController.php');

        $this->rule->processNode($this->createClassWithTag('Auth', 'Auth endpoints'), $scope1);
        $errors = $this->rule->processNode($this->createClassWithTag('Auth', 'Different description'), $scope2);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('conflicting descriptions', $errors[0]->getMessage());
        $this->assertStringContainsString("name: Auth)", $errors[0]->getMessage());
    }

    public function testErrorForMissingVsPresentDescription(): void
    {
        $scope1 = $this->createMock(Scope::class);
        $scope1->method('getFile')->willReturn('/src/FirstController.php');
        $scope2 = $this->createMock(Scope::class);
        $scope2->method('getFile')->willReturn('/src/SecondController.php');

        $this->rule->processNode($this->createClassWithTag('Catalog', 'Catalog endpoints'), $scope1);
        $errors = $this->rule->processNode($this->createClassWithTagNoDescription('Catalog'), $scope2);

        $this->assertCount(1, $errors);
    }

    public function testNoErrorForDifferentTagNames(): void
    {
        $scope = $this->createMock(Scope::class);
        $scope->method('getFile')->willReturn('/src/Controller.php');

        $this->rule->processNode($this->createClassWithTag('Auth', 'Auth endpoints'), $scope);
        $errors = $this->rule->processNode($this->createClassWithTag('Catalog', 'Catalog endpoints'), $scope);

        $this->assertSame([], $errors);
    }

    private function createClassWithTag(string $name, string $description): Class_
    {
        return $this->createClassWithName('Controller', [
            new AttributeGroup([
                new Attribute(new Name('OA\Tag'), [
                    new Arg(new String_($name), name: new Identifier('name')),
                    new Arg(new String_($description), name: new Identifier('description')),
                ]),
            ]),
        ]);
    }

    private function createClassWithTagNoDescription(string $name): Class_
    {
        return $this->createClassWithName('Controller', [
            new AttributeGroup([
                new Attribute(new Name('OA\Tag'), [
                    new Arg(new String_($name), name: new Identifier('name')),
                ]),
            ]),
        ]);
    }

    private function createClassWithName(string $className, array $attrGroups): Class_
    {
        $class = new Class_($className, ['attrGroups' => $attrGroups]);
        $class->namespacedName = new Name('App\\Controller\\' . $className);

        return $class;
    }
}
