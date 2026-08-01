<?php

declare(strict_types=1);

namespace Baander\PHPStan\Tests\Rules;

use Baander\PHPStan\Rules\MapRequestPayloadObjectTypeRule;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\TestCase;
use PHPStan\Analyser\Scope;

final class MapRequestPayloadObjectTypeRuleTest extends TestCase
{
    private MapRequestPayloadObjectTypeRule $rule;

    protected function setUp(): void
    {
        $this->rule = new MapRequestPayloadObjectTypeRule();
    }

    public function testNodeTypeIsClassMethod(): void
    {
        $this->assertSame(ClassMethod::class, $this->rule->getNodeType());
    }

    public function testNoErrorsWithoutMapRequestPayload(): void
    {
        $method = new ClassMethod('test', [
            'params' => [new Param(new Variable('data'), null, new Name('SomeDto'))],
        ]);
        $scope = $this->createMock(Scope::class);

        $errors = $this->rule->processNode($method, $scope);

        $this->assertSame([], $errors);
    }

    public function testNoErrorsWithProperDtoType(): void
    {
        $param = $this->createParamWithAttribute('payload', new Name('CreateRequest'), 'MapRequestPayload');
        $method = new ClassMethod('create', ['params' => [$param]]);
        $scope = $this->createMock(Scope::class);

        $errors = $this->rule->processNode($method, $scope);

        $this->assertSame([], $errors);
    }

    public function testErrorOnBareObjectType(): void
    {
        $param = $this->createParamWithAttribute('payload', new Name('object'), 'MapRequestPayload');
        $method = new ClassMethod('create', ['params' => [$param]]);
        $scope = $this->createMock(Scope::class);

        $errors = $this->rule->processNode($method, $scope);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('typed as "object"', $errors[0]->getMessage());
        $this->assertStringContainsString('$payload', $errors[0]->getMessage());
    }

    public function testErrorOnMissingType(): void
    {
        $param = $this->createParamWithAttribute('payload', null, 'MapRequestPayload');
        $method = new ClassMethod('create', ['params' => [$param]]);
        $scope = $this->createMock(Scope::class);

        $errors = $this->rule->processNode($method, $scope);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must have an explicit type hint', $errors[0]->getMessage());
    }

    public function testErrorWithFullyQualifiedAttributeName(): void
    {
        $param = $this->createParamWithAttribute('data', new Name('object'), 'Symfony\Component\HttpKernel\Attribute\MapRequestPayload');
        $method = new ClassMethod('handle', ['params' => [$param]]);
        $scope = $this->createMock(Scope::class);

        $errors = $this->rule->processNode($method, $scope);

        $this->assertCount(1, $errors);
    }

    public function testNoErrorOnMultipleParamsWithMixedAttributes(): void
    {
        $param1 = new Param(new Variable('uuid'), null, new Name('string'));
        $param2 = $this->createParamWithAttribute('payload', new Name('CreateRequest'), 'MapRequestPayload');
        $method = new ClassMethod('update', ['params' => [$param1, $param2]]);
        $scope = $this->createMock(Scope::class);

        $errors = $this->rule->processNode($method, $scope);

        $this->assertSame([], $errors);
    }

    /**
     * Param constructor: (var, default, type, byRef, variadic, attributes, flags, attrGroups)
     */
    private function createParamWithAttribute(string $varName, ?Name $type, string $attrName): Param
    {
        return new Param(
            var: new Variable($varName),
            default: null,
            type: $type,
            byRef: false,
            variadic: false,
            attributes: [],
            flags: 0,
            attrGroups: [
                new AttributeGroup([
                    new Attribute(new Name($attrName)),
                ]),
            ],
        );
    }
}
