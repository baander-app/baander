<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\DocGenerator;

use App\Command\DocGenerator\HtmlRenderer;
use App\Command\DocGenerator\Model\BoundedContext;
use App\Command\DocGenerator\Model\ClassInfo;
use App\Command\DocGenerator\Model\HandlerInfo;
use App\Command\DocGenerator\Model\RouteInfo;
use PHPUnit\Framework\TestCase;

final class HtmlRendererTest extends TestCase
{
    private string $outputDir;
    private HtmlRenderer $renderer;
    private const string PHPDOC_DIR = 'phpdoc';

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/baander_html_' . bin2hex(random_bytes(6));
        mkdir($this->outputDir, 0755, true);
        // writeCss() writes directly into css/, so the directory must pre-exist.
        mkdir($this->outputDir . '/css', 0755, true);
        $this->renderer = new HtmlRenderer();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->outputDir);
    }

    public function testRenderAllWritesCorePagesAndOneFilePerContext(): void
    {
        $context = $this->createContext();

        $this->renderer->renderAll([$context], $this->outputDir, self::PHPDOC_DIR);

        $this->assertFileExists($this->outputDir . '/api-reference.html');
        $this->assertFileExists($this->outputDir . '/domain-models.html');
        $this->assertFileExists($this->outputDir . '/architecture-guide.html');
        $this->assertFileExists($this->outputDir . '/contexts/auth.html');
    }

    public function testApiReferenceRendersRouteRowsWithPhpdocControllerLinks(): void
    {
        $this->renderer->renderAll([$this->createContext()], $this->outputDir, self::PHPDOC_DIR);

        $html = (string) file_get_contents($this->outputDir . '/api-reference.html');

        $this->assertStringContainsString('<h3>Auth</h3>', $html);
        $this->assertStringContainsString('<code>POST</code>', $html);
        $this->assertStringContainsString('<code>/api/users</code>', $html);
        $this->assertStringContainsString('create', $html);
        $this->assertStringContainsString('Create a user', $html);
        // Controller class link uses root-depth phpdoc path.
        $this->assertStringContainsString(
            'phpdoc/classes/App-Auth-Interface-Http-UserController.html',
            $html,
        );
    }

    public function testDomainModelsRendersAggregateRootsAndValueObjects(): void
    {
        $this->renderer->renderAll([$this->createContext()], $this->outputDir, self::PHPDOC_DIR);

        $html = (string) file_get_contents($this->outputDir . '/domain-models.html');

        $this->assertStringContainsString('Aggregate Root', $html);
        $this->assertStringContainsString('User', $html);
        $this->assertStringContainsString('Value Object', $html);
        $this->assertStringContainsString('Email', $html);
        // Aggregate root properties are listed.
        $this->assertStringContainsString('id, name, email', $html);
        $this->assertStringContainsString(
            'phpdoc/classes/App-Auth-Domain-Model-User.html',
            $html,
        );
    }

    public function testDomainModelsLabelsEnumsAsEnum(): void
    {
        $enum = new ClassInfo(
            fqcn: 'App\Auth\Domain\Model\UserRole',
            shortName: 'UserRole',
            namespace: 'App\Auth\Domain\Model',
            layer: 'Domain',
            isValueObject: true,
            isEnum: true,
        );
        $context = new BoundedContext(name: 'Auth', description: '', valueObjects: [$enum]);

        $this->renderer->renderAll([$context], $this->outputDir, self::PHPDOC_DIR);

        $html = (string) file_get_contents($this->outputDir . '/domain-models.html');

        $this->assertStringContainsString('<td>Enum</td>', $html);
    }

    public function testArchitectureGuideSummarizesLayersAndHandlers(): void
    {
        $this->renderer->renderAll([$this->createContext()], $this->outputDir, self::PHPDOC_DIR);

        $html = (string) file_get_contents($this->outputDir . '/architecture-guide.html');

        // Context name section.
        $this->assertStringContainsString('<h3>Auth</h3>', $html);
        // Layers listed comma-separated with the class count.
        $this->assertStringContainsString('<td>Domain, Application, Interface</td>', $html);
        $this->assertStringContainsString('<td>3</td>', $html);
        // CQRS handler table.
        $this->assertStringContainsString('CreateUserCommand', $html);
        $this->assertStringContainsString('CreateUserHandler', $html);
    }

    public function testContextOverviewRendersLayerSectionsAndBadges(): void
    {
        $this->renderer->renderAll([$this->createContext()], $this->outputDir, self::PHPDOC_DIR);

        $html = (string) file_get_contents($this->outputDir . '/contexts/auth.html');

        // Context name and description.
        $this->assertStringContainsString('<h1>Auth</h1>', $html);
        $this->assertStringContainsString('Authentication context', $html);
        // Layer section headers.
        $this->assertStringContainsString('<h3>Domain</h3>', $html);
        $this->assertStringContainsString('<h3>Interface</h3>', $html);
        // Badges.
        $this->assertStringContainsString('Aggregate Root', $html);
        $this->assertStringContainsString('Value Object', $html);
        // Context overview is one level deep, so phpdoc links get a ../ prefix.
        $this->assertStringContainsString(
            '../phpdoc/classes/App-Auth-Domain-Model-User.html',
            $html,
        );
        // Nav assets are one level deep.
        $this->assertStringContainsString('<link rel="stylesheet" href="../css/style.css">', $html);
        $this->assertStringContainsString('<a href="../index.html">Bånder Docs</a>', $html);
    }

    public function testWriteIndexLinksContextsAndPhpdocIndex(): void
    {
        $context = $this->createContext();

        $this->renderer->writeIndex(
            [$context],
            $this->outputDir,
            self::PHPDOC_DIR,
            ['operator-guide/index.html', 'developer-guide/index.html'],
        );

        $html = (string) file_get_contents($this->outputDir . '/index.html');

        // Context entry with name and description.
        $this->assertStringContainsString('contexts/auth.html', $html);
        $this->assertStringContainsString('Auth', $html);
        $this->assertStringContainsString('Authentication context', $html);
        // Guide links appear when converted pages contain them.
        $this->assertStringContainsString('operator-guide/index.html', $html);
        $this->assertStringContainsString('developer-guide/index.html', $html);
        // phpDocumentor index link at root depth.
        $this->assertStringContainsString('phpdoc/index.html', $html);
    }

    public function testWriteIndexOmitsGuideLinksWhenPagesAbsent(): void
    {
        $this->renderer->writeIndex([$this->createContext()], $this->outputDir, self::PHPDOC_DIR, []);

        $html = (string) file_get_contents($this->outputDir . '/index.html');

        $this->assertStringNotContainsString("Operator's Guide", $html);
        $this->assertStringNotContainsString("Developer's Guide", $html);
    }

    public function testWriteCssCopiesTheStylesheet(): void
    {
        $this->renderer->writeCss($this->outputDir);

        $written = file_get_contents($this->outputDir . '/css/style.css');
        $source = file_get_contents(__DIR__ . '/../../../../src/Command/DocGenerator/Resources/style.css');

        $this->assertNotFalse($written);
        $this->assertNotFalse($source);
        $this->assertSame($source, $written);
    }

    public function testHtmlIsEscapedInContextNameAndDescription(): void
    {
        $context = new BoundedContext(
            name: 'A & B',
            description: '<script>alert(1)</script>',
        );

        $this->renderer->writeIndex([$context], $this->outputDir, self::PHPDOC_DIR, []);

        $html = (string) file_get_contents($this->outputDir . '/index.html');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('A &amp; B', $html);
    }

    private function createContext(): BoundedContext
    {
        $aggregateRoot = new ClassInfo(
            fqcn: 'App\Auth\Domain\Model\User',
            shortName: 'User',
            namespace: 'App\Auth\Domain\Model',
            layer: 'Domain',
            description: 'The user aggregate.',
            properties: ['id', 'name', 'email'],
            isAggregateRoot: true,
        );
        $valueObject = new ClassInfo(
            fqcn: 'App\Auth\Domain\ValueObject\Email',
            shortName: 'Email',
            namespace: 'App\Auth\Domain\ValueObject',
            layer: 'Domain',
            description: 'An email value object.',
            isValueObject: true,
        );
        $controller = new ClassInfo(
            fqcn: 'App\Auth\Interface\Http\UserController',
            shortName: 'UserController',
            namespace: 'App\Auth\Interface\Http',
            layer: 'Interface',
        );
        $route = new RouteInfo(
            methods: 'POST',
            path: '/api/users',
            name: 'api_users_create',
            controllerFqcn: 'App\Auth\Interface\Http\UserController',
            methodName: 'create',
            description: 'Create a user',
        );
        $handler = new HandlerInfo(
            handlerFqcn: 'App\Auth\Application\CommandHandler\CreateUserHandler',
            handlerShortName: 'CreateUserHandler',
            commandFqcn: 'App\Auth\Application\Command\CreateUserCommand',
            commandShortName: 'CreateUserCommand',
            layer: 'Application',
        );

        return new BoundedContext(
            name: 'Auth',
            description: 'Authentication context',
            classes: [$aggregateRoot, $valueObject, $controller],
            routes: [$route],
            aggregateRoots: [$aggregateRoot],
            valueObjects: [$valueObject],
            handlers: [$handler],
            layers: ['Domain', 'Application', 'Interface'],
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($path);
    }
}
